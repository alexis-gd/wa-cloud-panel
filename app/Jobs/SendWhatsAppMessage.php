<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Services\WhatsApp\TemplateBuilder;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Máximo 3 intentos con backoff exponencial (regla seguridad)
    public int $tries   = 3;
    public int $backoff = 60; // segundos entre retries

    public function __construct(
        private readonly int    $contactId,
        private readonly int    $campaignId,
        private readonly int    $phoneNumberId,
        private readonly string $templateName,
        private readonly string $languageCode,
        private readonly array  $bodyVars = [],
    ) {}

    public function handle(WhatsAppClient $client, TemplateBuilder $builder): void
    {
        $contact     = Contact::find($this->contactId);
        $phoneNumber = PhoneNumber::find($this->phoneNumberId);
        $campaign    = Campaign::find($this->campaignId);

        // Si alguno ya no existe, descartar silenciosamente
        if (! $contact || ! $phoneNumber || ! $campaign) {
            Log::warning('SendWhatsAppMessage: entidad no encontrada', [
                'contact_id'      => $this->contactId,
                'campaign_id'     => $this->campaignId,
                'phone_number_id' => $this->phoneNumberId,
            ]);
            return;
        }

        // ── Si la campaña fue pausada, descartar sin log ──
        if ($campaign->status === 'paused') {
            Log::info('SendWhatsAppMessage: campaña pausada, descartando job silenciosamente', [
                'campaign_id' => $this->campaignId,
            ]);
            return;
        }

        // ── Circuit breaker: número pausado por rate limit o bloqueo ──
        if ($phoneNumber->isPaused()) {
            Log::info('SendWhatsAppMessage: número pausado (circuit breaker), reintentando', [
                'phone_number_id' => $this->phoneNumberId,
                'paused_until'    => $phoneNumber->paused_until,
            ]);
            $this->release($phoneNumber->paused_until->diffInSeconds(now()) + 5);
            return;
        }

        // ── Verificar opt-out ANTES de enviar (regla inquebrantable) ──
        if ($contact->status === 'opted_out' || $contact->status === 'invalid') {
            Log::info('SendWhatsAppMessage: contacto con opt-out/inválido, descartando', [
                'contact_id' => $this->contactId,
            ]);
            MessageLog::logDiscard($this->phoneNumberId, $this->campaignId, $contact->phone, $this->templateName, $this->languageCode, 'opted_out');
            $campaign->increment('failed_count');
            $this->checkAutoComplete($campaign);
            return;
        }

        // ── Verificar snooze (contacto pidió "No por ahora") ──
        if ($contact->isSnoozeActive()) {
            Log::info('SendWhatsAppMessage: contacto en snooze, descartando', [
                'contact_id'    => $this->contactId,
                'snoozed_until' => $contact->snoozed_until,
            ]);
            MessageLog::logDiscard($this->phoneNumberId, $this->campaignId, $contact->phone, $this->templateName, $this->languageCode, 'snooze');
            $campaign->increment('failed_count');
            $this->checkAutoComplete($campaign);
            return;
        }

        // ── Dedup: no enviar más de 1 mensaje/día al mismo contacto (hora México) ──
        $startOfDay = now('America/Mexico_City')->startOfDay()->utc();
        $endOfDay   = now('America/Mexico_City')->endOfDay()->utc();

        $alreadySentToday = MessageLog::where('to_number', $contact->phone)
            ->whereBetween('sent_at', [$startOfDay, $endOfDay])
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->exists();

        if ($alreadySentToday) {
            Log::info('SendWhatsAppMessage: contacto ya recibió mensaje hoy, descartando', [
                'contact_id' => $this->contactId,
                'phone'      => substr($contact->phone, -4),
            ]);
            MessageLog::logDiscard($this->phoneNumberId, $this->campaignId, $contact->phone, $this->templateName, $this->languageCode, 'dedup_today');
            $campaign->increment('failed_count');
            $this->checkAutoComplete($campaign);
            return;
        }

        // ── Cooldown: no enviar al mismo contacto en N días (mínimo 7, default 30) ──
        $cooldownDays = max(7, (int) Setting::get('cooldown_days', 30));
        $lastSent     = MessageLog::where('to_number', $contact->phone)
            ->where('status', 'sent')
            ->latest('sent_at')
            ->value('sent_at');

        if ($lastSent && now()->diffInDays($lastSent) < $cooldownDays) {
            Log::info('SendWhatsAppMessage: contacto en cooldown, descartando', [
                'contact_id'    => $this->contactId,
                'phone'         => substr($contact->phone, -4),
                'last_sent'     => $lastSent,
                'cooldown_days' => $cooldownDays,
            ]);
            MessageLog::logDiscard($this->phoneNumberId, $this->campaignId, $contact->phone, $this->templateName, $this->languageCode, 'cooldown');
            $campaign->increment('failed_count');
            $this->checkAutoComplete($campaign);
            return;
        }

        // ── Warm-up: respetar límite diario del número (hora México) ──
        $sentToday = MessageLog::where('phone_number_id', $this->phoneNumberId)
            ->whereBetween('sent_at', [$startOfDay, $endOfDay])
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->count();

        if ($sentToday >= $phoneNumber->daily_limit) {
            Log::info('SendWhatsAppMessage: límite diario alcanzado, reintentando mañana', [
                'phone_number_id' => $this->phoneNumberId,
                'sent_today'      => $sentToday,
                'daily_limit'     => $phoneNumber->daily_limit,
            ]);
            // Reencolar al inicio de ventana del día siguiente (9AM CST)
            $nextWindow = now('America/Mexico_City')->addDay()->startOfDay()->addHours(9);
            $this->release($nextWindow->diffInSeconds(now()));
            return;
        }

        // ── Crear log ANTES de llamar a la API ──
        $log = MessageLog::logSend(
            $this->phoneNumberId,
            $contact->phone,
            $this->templateName,
            $this->languageCode,
            $this->bodyVars,
            $this->campaignId,
        );

        $payload  = $builder->build($contact->phone, $this->templateName, $this->languageCode, $this->bodyVars);
        $response = $client->post($phoneNumber->phone_number_id, $phoneNumber->token, $payload);

        $log->updateFromResponse($response);

        if ($response['ok']) {
            $campaign->increment('sent_count');
            $this->checkAutoComplete($campaign);
            return;
        }

        // ── Manejo de errores Meta ──
        $campaign->increment('failed_count');
        $errorCode = $response['body']['error']['code'] ?? null;

        // 131026: número inexistente — marcar inválido, no reintentar
        if ($errorCode === 131026) {
            $contact->update(['status' => 'invalid']);
            $this->checkAutoComplete($campaign);
            $this->fail(new \RuntimeException("Número inexistente en WhatsApp: {$contact->phone}"));
            return;
        }

        // 131048: spam rate limit — circuit breaker 60 minutos
        if ($errorCode === 131048) {
            $phoneNumber->pauseFor(60);
            Log::error('SendWhatsAppMessage: spam rate limit (131048) — número pausado 60 min', [
                'phone_number_id' => $this->phoneNumberId,
                'paused_until'    => $phoneNumber->fresh()->paused_until,
            ]);
            $this->release(3600);
            return;
        }

        // 368: cuenta bloqueada — circuit breaker 24 horas + desactivar número
        if ($errorCode === 368) {
            $phoneNumber->pauseFor(1440); // 24 horas
            Log::critical('SendWhatsAppMessage: cuenta bloqueada (368) — número pausado 24h, revisar Business Manager', [
                'phone_number_id' => $this->phoneNumberId,
                'paused_until'    => $phoneNumber->fresh()->paused_until,
            ]);
            $this->checkAutoComplete($campaign);
            $this->fail(new \RuntimeException('Cuenta Meta bloqueada temporalmente (368).'));
            return;
        }

        // Otros errores: dejar que el sistema reintente (hasta $tries)
        $this->checkAutoComplete($campaign);
        throw new \RuntimeException("Error Meta {$errorCode}: " . json_encode($response['body']));
    }

    public function failed(\Throwable $e): void
    {
        Log::error('SendWhatsAppMessage: job fallido definitivamente', [
            'contact_id'  => $this->contactId,
            'campaign_id' => $this->campaignId,
            'error'       => $e->getMessage(),
        ]);
    }

    // Marca la campaña como 'done' si ya se procesaron todos los contactos
    private function checkAutoComplete(Campaign $campaign): void
    {
        $fresh = $campaign->fresh();
        if (
            $fresh &&
            $fresh->status === 'running' &&
            $fresh->total_contacts > 0 &&
            ($fresh->sent_count + $fresh->failed_count) >= $fresh->total_contacts
        ) {
            $fresh->update(['status' => 'completed', 'completed_at' => now()]);
        }
    }
}
