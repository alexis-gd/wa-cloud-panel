<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Events\CampaignProgressUpdated;
use App\Services\WhatsApp\MessagePersonalizer;
use App\Services\WhatsApp\PortfolioLimit;
use App\Services\WhatsApp\SendWindow;
use App\Services\WhatsApp\TemplateBuilder;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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

        // ── Ventana de envío: L-V 9AM-10PM CST. Fuera de hora, reencolar hasta la
        //    próxima apertura. Va aquí (no solo en el despacho) para que el horario se
        //    respete aunque el worker corra 24/7 por Supervisor: cubre campañas grandes
        //    que cruzan las 22:00 con cola pendiente. El modo demo (schedule_bypass) lo salta. ──
        if (! SendWindow::isOpen()) {
            $next = SendWindow::nextOpening();
            Log::info('SendWhatsAppMessage: fuera de ventana de envío, reencolando hasta la próxima apertura', [
                'contact_id'   => $this->contactId,
                'next_opening' => $next->toDateTimeString(),
            ]);
            $this->release($next->diffInSeconds(now()));
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

        // ── Verificar inalcanzable (marcado por wa:mark-unreachable) ──
        // scopeActive ya lo excluye de campañas nuevas; esto protege jobs encolados
        // antes de que el contacto fuera marcado.
        if ($contact->status === 'unreachable') {
            Log::info('SendWhatsAppMessage: contacto inalcanzable, descartando', [
                'contact_id' => $this->contactId,
            ]);
            MessageLog::logDiscard($this->phoneNumberId, $this->campaignId, $contact->phone, $this->templateName, $this->languageCode, 'unreachable');
            $campaign->increment('failed_count');
            $this->checkAutoComplete($campaign);
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

        // ── Hold 131049: Meta capó a este contacto por su tope de marketing. Esperar
        //    24h antes de reintentarle (reintentar antes lo bloquea hasta 24h más). ──
        if ($contact->isWaMarketingHoldActive()) {
            Log::info('SendWhatsAppMessage: contacto en hold de marketing (131049), descartando', [
                'contact_id'    => $this->contactId,
                'hold_until'    => $contact->wa_marketing_hold_until,
            ]);
            MessageLog::logDiscard($this->phoneNumberId, $this->campaignId, $contact->phone, $this->templateName, $this->languageCode, 'marketing_hold');
            $campaign->increment('failed_count');
            $this->checkAutoComplete($campaign);
            return;
        }

        // ── Dedup: no enviar más de 1 mensaje/día al mismo contacto (hora México) ──
        $startOfDay = now('America/Mexico_City')->startOfDay()->utc();
        $endOfDay   = now('America/Mexico_City')->endOfDay()->utc();

        $alreadySentToday = MessageLog::where('to_number', $contact->phone)
            ->where('channel', 'whatsapp')
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
            ->where('channel', 'whatsapp')
            ->whereIn('status', ['sent', 'delivered', 'read'])
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

        // ── Freno del portfolio: no enviar más del techo de la CUENTA (compartido por todos
        //    los números). Meta lo impone por portfolio; lo respetamos antes de intentar para
        //    no provocar rechazos (131048/131049). Si Meta aún no reportó el límite, no frena. ──
        $portfolioCeiling = PortfolioLimit::daily();
        if ($portfolioCeiling !== null) {
            $sentTodayAll = MessageLog::whereBetween('sent_at', [$startOfDay, $endOfDay])
                ->whereIn('status', ['sent', 'delivered', 'read'])
                ->count();

            if ($sentTodayAll >= $portfolioCeiling) {
                Log::info('SendWhatsAppMessage: límite del portfolio alcanzado, reintentando mañana', [
                    'sent_today_all'    => $sentTodayAll,
                    'portfolio_ceiling' => $portfolioCeiling,
                ]);
                $nextWindow = now('America/Mexico_City')->addDay()->startOfDay()->addHours(9);
                $this->release($nextWindow->diffInSeconds(now()));
                return;
            }
        }

        // ── Idempotencia: si ya existe un log pending para este contacto+campaña
        //    significa que un intento anterior llegó a Meta pero timed out antes
        //    de recibir respuesta — no reenviar para evitar duplicados ──
        $existingPending = MessageLog::where('campaign_id', $this->campaignId)
            ->where('to_number', $contact->phone)
            ->where('status', 'pending')
            ->exists();

        if ($existingPending) {
            Log::warning('SendWhatsAppMessage: log pending previo encontrado — posible envío anterior exitoso, descartando retry para evitar duplicado', [
                'contact_id'  => $this->contactId,
                'campaign_id' => $this->campaignId,
            ]);
            $campaign->increment('sent_count');
            $this->checkAutoComplete($campaign);
            return;
        }

        // Resolver {nombre} con los datos de ESTE contacto. Se hace antes del log para que el
        // historial guarde lo que de verdad se mandó, no la plantilla con el marcador.
        $bodyVars = (new MessagePersonalizer())->resolve($this->bodyVars, $contact);

        // ── Crear log ANTES de llamar a la API ──
        $log = MessageLog::logSend(
            $this->phoneNumberId,
            $contact->phone,
            $this->templateName,
            $this->languageCode,
            $bodyVars,
            $this->campaignId,
        );

        $payload  = $builder->build($contact->phone, $this->templateName, $this->languageCode, $bodyVars);
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

        // 131048: spam rate limit — circuit breaker 60 minutos + warm-down
        if ($errorCode === 131048) {
            $phoneNumber->pauseFor(60);
            $phoneNumber->backOffDailyLimit();
            Log::error('SendWhatsAppMessage: spam rate limit (131048) — número pausado 60 min', [
                'phone_number_id' => $this->phoneNumberId,
                'paused_until'    => $phoneNumber->fresh()->paused_until,
            ]);
            $this->release(3600);
            return;
        }

        // 131064: la cuenta llegó a su límite por infracciones de categorización de
        // plantillas (afecta a TODA la WABA, plantilla y directo). Se levanta solo tras
        // el periodo de aplicación. Pausar el número y avisar para revisión de categorías.
        if ($errorCode === 131064) {
            $phoneNumber->pauseFor(60);
            $phoneNumber->backOffDailyLimit();
            Log::critical('SendWhatsAppMessage: límite por categorización de plantillas (131064) — número pausado 60 min, revisar categorías en Business Manager', [
                'phone_number_id' => $this->phoneNumberId,
                'paused_until'    => $phoneNumber->fresh()->paused_until,
            ]);
            $this->release(3600);
            return;
        }

        // 368: cuenta bloqueada — circuit breaker 24 horas + desactivar número
        if ($errorCode === 368) {
            $phoneNumber->pauseFor(1440); // 24 horas
            $phoneNumber->backOffDailyLimit();
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
