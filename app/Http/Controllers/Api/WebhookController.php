<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MessageLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    // Palabras que disparan opt-out permanente (coincidencia exacta de palabra completa, case-insensitive)
    private const OPT_OUT_WORDS = ['STOP', 'BAJA', 'CANCELAR', 'NO'];

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

        // Procesar cambios de status (delivered, read, failed)
        // data_get con wildcards retorna array anidado — Arr::collapse lo aplana un nivel
        foreach (Arr::collapse(data_get($body, 'entry.*.changes.*.value.statuses', [[]])) as $statusEvent) {
            $waMessageId = $statusEvent['id']     ?? null;
            $status      = $statusEvent['status'] ?? null;

            if ($waMessageId && $status) {
                $log = MessageLog::where('wa_message_id', $waMessageId)->first();
                $log?->updateStatus($status);

                // Sincronizar status en conversations (mensajes salientes)
                Conversation::where('wa_message_id', $waMessageId)
                    ->update(['status' => $status]);
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

        // Buscar contacto por número (normalizado a 12 dígitos: 52XXXXXXXXXX)
        $phone   = ltrim($from, '+');
        $contact = Contact::where('phone', $phone)->first();

        if (!$contact) {
            Log::info('Webhook inbound: contacto no encontrado', ['from' => $phone]);
            return;
        }

        // Extraer texto o id del botón según tipo de mensaje
        [$body, $messageType] = match ($type) {
            'text'        => [$message['text']['body'] ?? '', 'text'],
            'interactive' => [
                data_get($message, 'interactive.button_reply.title', ''),
                'button_reply',
            ],
            default => ['', 'text'],
        };

        $buttonId = data_get($message, 'interactive.button_reply.id', '');

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

        // Procesar intención del mensaje
        if ($messageType === 'button_reply') {
            $this->handleButtonReply($contact, $buttonId, $body);
        } else {
            $this->handleTextMessage($contact, $body);
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

    private function handleTextMessage(Contact $contact, string $body): void
    {
        // Comparar el mensaje completo (normalizado) con las palabras de opt-out.
        // Exigir mensaje exacto evita falsos positivos: "no me cae" no es opt-out, "NO" sí.
        $normalized = strtoupper(trim($body));

        if (in_array($normalized, self::OPT_OUT_WORDS, true)) {
            $contact->optOut();
            Log::info("Opt-out por texto '{$body}' — contacto {$contact->id}");
        }
    }
}
