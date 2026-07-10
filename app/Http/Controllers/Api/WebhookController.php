<?php

namespace App\Http\Controllers\Api;

use App\Events\CampaignProgressUpdated;
use App\Events\ConversationUpdated;
use App\Events\InboundMessageReceived;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Services\AssignmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    // Palabras que disparan opt-out permanente (coincidencia exacta de palabra completa, case-insensitive)
    private const OPT_OUT_WORDS = ['STOP', 'BAJA', 'CANCELAR', 'NO'];

    private const DELIVERY_ERROR_MESSAGES = [
        131049 => 'El destinatario alcanzó su límite de mensajes de marketing. No es un problema del número.',
        131050 => 'El destinatario se dio de baja de mensajes de marketing en WhatsApp.',
        131048 => 'Entrega pausada por límite de envíos. Se reanudará automáticamente.',
        131064 => 'Cuenta pausada por categorización de plantillas. Se reanudará automáticamente.',
        131026 => 'El mensaje no pudo ser entregado al destinatario.',
        368    => 'Cuenta temporalmente restringida por Meta.',
        132001 => 'La plantilla no está aprobada en Meta.',
        132007 => 'La plantilla infringe una política de WhatsApp.',
        132015 => 'La plantilla está pausada por baja calidad.',
        132016 => 'La plantilla se desactivó de forma permanente por baja calidad.',
    ];

    public function __construct(private readonly AssignmentService $assignmentService) {}

    // GET /webhook — Meta verifica la URL enviando un hub.challenge
    public function verify(Request $request): Response
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.webhook_verify_token')) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    // POST /webhook — eventos de Meta (delivery, read, inbound)
    public function handle(Request $request): Response
    {
        // Validar firma X-Hub-Signature-256
        $signature = $request->header('X-Hub-Signature-256', '');
        $expected  = 'sha256=' . hash_hmac('sha256', $request->getContent(), config('services.whatsapp.app_secret'));

        if (!hash_equals($expected, $signature)) {
            Log::warning('Webhook signature mismatch');
            return response('Forbidden', 403);
        }

        $body = $request->json()->all();

        // Campañas cuyos mensajes cambiaron de status en este webhook. Meta manda los
        // statuses en lote, así que emitimos 1 evento por campaña al final (no 1 por mensaje):
        // el modal abierto refresca la fila (Enviado -> Entregado -> Leído -> Fallido) en vivo.
        $touchedCampaignIds = [];
        // Contactos cuyos mensajes salientes cambiaron de status: el chat abierto refresca
        // los checks (enviado -> entregado -> leído) en vivo.
        $touchedConversationContactIds = [];

        // Procesar cambios de status (delivered, read, failed)
        // data_get con wildcards retorna array anidado — Arr::collapse lo aplana un nivel
        foreach (Arr::collapse(data_get($body, 'entry.*.changes.*.value.statuses', [[]])) as $statusEvent) {
            $waMessageId = $statusEvent['id']     ?? null;
            $status      = $statusEvent['status'] ?? null;

            if ($waMessageId && $status) {
                $log = MessageLog::where('wa_message_id', $waMessageId)->first();

                if ($log?->campaign_id) {
                    $touchedCampaignIds[] = $log->campaign_id;
                }

                if ($status === 'failed') {
                    $errorData  = $statusEvent['errors'][0] ?? [];
                    $errorCode  = isset($errorData['code']) ? (int) $errorData['code'] : null;
                    $errorTitle = $errorData['title'] ?? null;

                    $log?->updateStatus($status, $errorCode, $errorTitle);

                    Log::warning('Webhook: message delivery failed', [
                        'wa_message_id' => $waMessageId,
                        'log_id'        => $log?->id,
                        'error_code'    => $errorCode,
                        'error_title'   => $errorTitle,
                    ]);

                    // 131049: tope de marketing POR USUARIO (frecuencia del destinatario,
                    // suma de todas las empresas). NO es un problema del número — no pausar.
                    // Solo falla ese mensaje. Meta exige esperar 24h antes de reintentar a
                    // ESE contacto (reintentar antes lo bloquea hasta 24h más) → hold 24h.
                    if ($errorCode === 131049 && $log?->to_number) {
                        $contact = Contact::where('phone', $log->to_number)->first();
                        if ($contact) {
                            $contact->holdWaMarketingFor24h();
                        }
                        Log::info('Webhook: tope de marketing por usuario (131049) — hold 24h al contacto, número sin pausar', [
                            'log_id'    => $log?->id,
                            'to_number' => $log?->to_number,
                        ]);
                    }

                    // 131050: el usuario se dio de baja de marketing desde WhatsApp (baja a
                    // nivel Meta, sin escribirnos texto). Marcar opt-out cross-channel.
                    if ($errorCode === 131050 && $log?->to_number) {
                        $contact = Contact::where('phone', $log->to_number)->first();
                        if ($contact && $contact->status !== 'opted_out') {
                            $contact->optOut('whatsapp_131050');
                            Log::info("Webhook: baja a nivel WhatsApp (131050) — contacto {$contact->id} marcado opt-out");
                        }
                    }

                    // 131064: límite de la cuenta por categorización de plantillas (afecta
                    // toda la WABA). Pausar el número para no seguir chocando con el límite.
                    if ($errorCode === 131064 && $log?->phone_number_id) {
                        $phoneNumber = PhoneNumber::find($log->phone_number_id);
                        if ($phoneNumber && ! $phoneNumber->isPaused()) {
                            $phoneNumber->pauseFor(60);
                            $phoneNumber->backOffDailyLimit();
                            Log::critical('Webhook: límite por categorización de plantillas (131064) — número pausado 60 min', [
                                'phone_number_id' => $log->phone_number_id,
                                'paused_until'    => $phoneNumber->fresh()->paused_until,
                            ]);
                        }
                    }

                    $this->createFailedDeliveryNotification($log, $errorCode);
                } else {
                    $log?->updateStatus($status);

                    if (in_array($status, ['delivered', 'read']) && $log) {
                        AppNotification::where('type', 'delivery_failed')
                            ->where('body', 'like', "%{$log->to_number}%")
                            ->delete();
                    }
                }

                // Sincronizar status en conversations (mensajes salientes)
                $convContactId = Conversation::where('wa_message_id', $waMessageId)->value('contact_id');
                Conversation::where('wa_message_id', $waMessageId)
                    ->update(['status' => $status]);
                if ($convContactId) {
                    $touchedConversationContactIds[] = $convContactId;
                }
            }
        }

        // Refrescar en vivo el chat abierto de cada contacto tocado (checks de entrega).
        foreach (array_unique($touchedConversationContactIds) as $contactId) {
            event(new ConversationUpdated($contactId));
        }

        // Emitir progreso en vivo por cada campaña tocada (sin throttle: el estado terminal
        // no se puede perder). El listener del modal refetch lee el estado completo de las filas.
        foreach (array_unique($touchedCampaignIds) as $campaignId) {
            if ($campaign = Campaign::find($campaignId)) {
                event(CampaignProgressUpdated::fromCampaign($campaign));
            }
        }

        // Procesar mensajes entrantes
        foreach (Arr::collapse(data_get($body, 'entry.*.changes.*.value.messages', [[]])) as $message) {
            $this->handleInboundMessage($message);
        }

        return response('EVENT_RECEIVED', 200);
    }

    private function handleInboundMessage(array $message): void
    {
        $from = $message['from'] ?? null; // número en formato E.164 sin +
        $type = $message['type'] ?? null;

        if (!$from) {
            return;
        }

        // Normalizar número: Meta envía 5219XXXXXXXX (13 dígitos) para México móvil
        // Nosotros guardamos 529XXXXXXXX (12 dígitos) — quitamos el 1 extra
        $phone = ltrim($from, '+');
        if (str_starts_with($phone, '521') && strlen($phone) === 13) {
            $phone = '52' . substr($phone, 3);
        }
        $contact = Contact::where('phone', $phone)->first();

        if (!$contact) {
            Log::info('Webhook inbound: contacto no encontrado', ['from' => $phone]);
            return;
        }

        // Extraer texto o id del botón según tipo de mensaje
        // 'button'      = respuesta a botón Quick Reply de una plantilla
        // 'interactive' = respuesta a mensaje interactivo standalone
        [$body, $messageType] = match ($type) {
            'text'        => [$message['text']['body'] ?? '', 'text'],
            'button'      => [data_get($message, 'button.text', ''), 'button_reply'],
            'interactive' => [
                data_get($message, 'interactive.button_reply.title', ''),
                'button_reply',
            ],
            default => ['', 'text'],
        };

        $buttonId = data_get($message, 'interactive.button_reply.id')
                 ?? data_get($message, 'button.payload', '');

        // Guardar mensaje en tabla conversations (abre ventana 24h)
        $conversation = Conversation::create([
            'contact_id'   => $contact->id,
            'direction'    => 'inbound',
            'message_type' => $messageType,
            'body'         => $body,
            'wa_message_id' => $message['id'] ?? null,
            'status'       => 'received',
            'window_open'  => true,
        ]);

        // Tiempo real: avisar al panel que llego una respuesta (se muestra sin recargar).
        InboundMessageReceived::dispatch(
            $contact->id,
            $contact->name,
            $body,
            'whatsapp',
            $conversation->created_at->setTimezone('America/Mexico_City')->format('Y-m-d H:i'),
        );

        // Procesar la intención del mensaje PRIMERO, para saber si fue una baja antes de asignar.
        $optedOut = false;
        if ($messageType === 'button_reply') {
            $this->handleButtonReply($contact, $buttonId, $body);
        } else {
            $optedOut = $this->handleTextMessage($contact, $body);
        }

        // Asignación de agente según la intención:
        // - Baja: soltar la conversación (si tenía agente) - no hay a quién ni por qué atender.
        // - Cualquier otra respuesta: auto-asignar en el primer inbound (genera chat y se atiende).
        if ($optedOut) {
            $this->assignmentService->unassign($contact->id);
        } elseif (! $contact->assignments()->exists()) {
            $this->assignmentService->autoAssign($contact->id);
        }

        Log::info('Webhook inbound procesado', [
            'contact_id' => $contact->id,
            'type'       => $messageType,
            'body'       => substr($body, 0, 50),
        ]);
    }

    private function handleButtonReply(Contact $contact, string $buttonId, string $title): void
    {
        // Botón "No por ahora" → snooze por cooldown_days
        if (str_contains(strtolower($title), 'no por ahora') || $buttonId === 'snooze') {
            $days = (int) Setting::get('cooldown_days', 30);
            $contact->snooze($days);
            Log::info("Snooze activado para contacto {$contact->id} por {$days} días");
            return;
        }

        // Botón "Me interesa" → marcar como lead (sin cambio de status por ahora, solo log)
        if (str_contains(strtolower($title), 'me interesa') || $buttonId === 'interested') {
            Log::info("Contacto {$contact->id} marcó interés — pendiente de atención por agente");
        }
    }

    /**
     * Procesa un mensaje de texto entrante. Devuelve true si fue una baja (opt-out), para que
     * el llamador sepa que NO debe auto-asignar un agente a ese contacto.
     */
    private function handleTextMessage(Contact $contact, string $body): bool
    {
        // Comparar el mensaje completo (normalizado) con las palabras de opt-out.
        // Exigir mensaje exacto evita falsos positivos: "no me cae" no es opt-out, "NO" sí.
        $normalized = strtoupper(trim($body));

        if (in_array($normalized, self::OPT_OUT_WORDS, true)) {
            $contact->optOut('auto');
            Log::info("Opt-out por texto '{$body}' - contacto {$contact->id}");
            return true;
        }

        return false;
    }

    private function createFailedDeliveryNotification(?MessageLog $log, ?int $errorCode): void
    {
        if (! $log) {
            return;
        }

        $humanMessage = self::DELIVERY_ERROR_MESSAGES[$errorCode] ?? 'Error de entrega desconocido.';

        $contact     = Contact::where('phone', $log->to_number)->first();
        $contactDesc = $contact
            ? "{$contact->name} ({$log->to_number})"
            : $log->to_number;

        AppNotification::create([
            'type'  => 'delivery_failed',
            'title' => 'Entrega fallida',
            'body'  => "El mensaje a {$contactDesc} no fue entregado. {$humanMessage}",
        ]);
    }
}
