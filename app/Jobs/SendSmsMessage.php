<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\Setting;
use App\Events\CampaignProgressUpdated;
use App\Services\Sms\SmsGatewayClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Envío SMS por gateway. Job SEPARADO de SendWhatsAppMessage (regla contexto-sms):
 * si el gateway SMS se atora, WhatsApp sigue y viceversa.
 *
 * Diferencias clave con el job WA:
 *  - SMS NO tiene horario forzado (el cliente elige cuándo; ver contexto-sms).
 *  - Opt-out es CROSS-CHANNEL (una baja en WhatsApp también bloquea SMS — legal/seguridad).
 *  - Dedup y cooldown son POR CANAL (WA y SMS independientes: un WA hoy no frena el SMS).
 *  - El pool de chips lo resuelve el gateway, no el panel: no hay phone_number_id.
 */
class SendSmsMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Máximo 3 intentos con backoff exponencial (misma regla que el job WA).
    public int $tries   = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly int    $contactId,
        private readonly int    $campaignId,
        private readonly string $body,
    ) {}

    public function handle(SmsGatewayClient $client): void
    {
        $contact  = Contact::find($this->contactId);
        $campaign = Campaign::find($this->campaignId);

        if (! $contact || ! $campaign) {
            Log::warning('SendSmsMessage: entidad no encontrada', [
                'contact_id'  => $this->contactId,
                'campaign_id' => $this->campaignId,
            ]);
            return;
        }

        // ── Campaña pausada → descartar silenciosamente ──
        if ($campaign->status === 'paused') {
            Log::info('SendSmsMessage: campaña pausada, descartando job', ['campaign_id' => $this->campaignId]);
            return;
        }

        // ── Opt-out CROSS-CHANNEL: baja de WhatsApp bloquea también SMS ──
        if ($contact->status === 'opted_out') {
            $this->discard($campaign, $contact, 'opted_out');
            return;
        }

        // ── Opt-out / bloqueo / inválido específicos de SMS ──
        if ($contact->isSmsBlocked()) {
            $this->discard($campaign, $contact, 'sms_blocked');
            return;
        }

        // ── Snooze es POR CANAL (solo WhatsApp): el "No por ahora" nace de un botón de
        //    plantilla WhatsApp y pausa SOLO WhatsApp. SMS no lo respeta (el cliente impacta
        //    por ambos canales por separado, igual que dedup/cooldown). Ver contexto-sms. ──

        // ── Dedup POR CANAL: no reenviar SMS si ya recibió un SMS hoy ──
        // (WhatsApp y SMS son independientes: un WA hoy no frena el SMS.)
        $startOfDay = now('America/Mexico_City')->startOfDay()->utc();
        $endOfDay   = now('America/Mexico_City')->endOfDay()->utc();

        $alreadySentToday = MessageLog::where('to_number', $contact->phone)
            ->where('channel', 'sms')
            ->whereBetween('sent_at', [$startOfDay, $endOfDay])
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->exists();

        if ($alreadySentToday) {
            $this->discard($campaign, $contact, 'dedup_today');
            return;
        }

        // ── Cooldown POR CANAL: no reimpactar por SMS en N días (solo mira SMS) ──
        $cooldownDays = max(7, (int) Setting::get('cooldown_days', 30));
        $lastSent     = MessageLog::where('to_number', $contact->phone)
            ->where('channel', 'sms')
            ->where('status', 'sent')
            ->latest('sent_at')
            ->value('sent_at');

        if ($lastSent && now()->diffInDays($lastSent) < $cooldownDays) {
            $this->discard($campaign, $contact, 'cooldown');
            return;
        }

        // ── Idempotencia: log pending previo → posible envío anterior exitoso ──
        $existingPending = MessageLog::where('campaign_id', $this->campaignId)
            ->where('to_number', $contact->phone)
            ->where('channel', 'sms')
            ->where('status', 'pending')
            ->exists();

        if ($existingPending) {
            Log::warning('SendSmsMessage: log pending previo, descartando retry para evitar duplicado', [
                'contact_id'  => $this->contactId,
                'campaign_id' => $this->campaignId,
            ]);
            $campaign->increment('sent_count');
            $this->checkAutoComplete($campaign);
            return;
        }

        // ── Crear log ANTES de llamar al gateway ──
        $log      = MessageLog::logSmsSend($contact->phone, $this->body, $this->campaignId);
        $response = $client->send($contact->phone, $this->body);

        $log->updateFromSmsResponse($response);

        if ($response['ok']) {
            $campaign->increment('sent_count');
            $this->checkAutoComplete($campaign);
            return;
        }

        // Error del gateway: contar como fallido y reintentar (hasta $tries).
        // Los estados de entrega (delivered/failed) y el opt-out (STOP entrante) llegan por
        // webhook, no en la respuesta del envío — se manejan en SmsWebhookController.
        $campaign->increment('failed_count');
        $this->checkAutoComplete($campaign);

        throw new \RuntimeException('SMS gateway error: ' . json_encode($response['error']));
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SendSmsMessage: job fallido definitivamente', [
            'contact_id'  => $this->contactId,
            'campaign_id' => $this->campaignId,
            'error'       => $e->getMessage(),
        ]);
    }

    // Registra descarte, incrementa failed_count y revisa auto-completado.
    private function discard(Campaign $campaign, Contact $contact, string $reason): void
    {
        Log::info("SendSmsMessage: descartando ({$reason})", [
            'contact_id' => $contact->id,
            'phone'      => substr($contact->phone, -4),
        ]);
        MessageLog::logSmsDiscard($this->campaignId, $contact->phone, $this->body, $reason);
        $campaign->increment('failed_count');
        $this->checkAutoComplete($campaign);
    }

    // Marca la campaña como completada si ya se procesaron todos los contactos.
    private function checkAutoComplete(Campaign $campaign): void
    {
        $fresh = $campaign->fresh();
        if (! $fresh) {
            return;
        }

        $justCompleted = false;
        if (
            $fresh->status === 'running' &&
            $fresh->total_contacts > 0 &&
            ($fresh->sent_count + $fresh->failed_count) >= $fresh->total_contacts
        ) {
            $fresh->update(['status' => 'completed', 'completed_at' => now()]);
            $justCompleted = true;
        }

        $this->broadcastProgress($fresh, $justCompleted);
    }

    // Emite el progreso al panel por WebSocket (tiempo real, sin polling).
    // El evento final (campaña completada) siempre sale; los intermedios van con
    // throttle (máx 1 cada 3s por campaña) para no lanzar miles de broadcasts en un blast.
    private function broadcastProgress(Campaign $campaign, bool $force = false): void
    {
        if (! $force && ! Cache::add("campaign_progress_{$campaign->id}", 1, 3)) {
            return;
        }
        event(CampaignProgressUpdated::fromCampaign($campaign));
    }
}
