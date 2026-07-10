<?php

namespace App\Http\Controllers\Api;

use App\Events\CampaignProgressUpdated;
use App\Events\InboundMessageReceived;
use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\Setting;
use App\Models\SmsInboundMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook del gateway SMS (SMS Gateway for Android™, capcom6).
 *
 * A diferencia de Twilio, el gateway Android NO expone códigos de carrier (21610, 30004…).
 * Sus eventos son sms:sent | sms:delivered | sms:failed | sms:received. El mapeo a la lógica
 * de auto-protección (contexto-sms) es:
 *   - sms:failed   → rebote SMS (3 consecutivos ⇒ sms_blocked)
 *   - sms:received → si el texto es STOP/BAJA/CANCELAR/NO ⇒ opt-out SMS
 *
 * Opt-out es CROSS-CHANNEL en el envío (un opt-out WA bloquea SMS), pero un STOP recibido
 * por SMS marca sms_opt_out sin tocar el status WA (el usuario pidió baja de SMS, no de WA).
 */
class SmsWebhookController extends Controller
{
    // Mismas palabras que el webhook de WhatsApp (coincidencia exacta, case-insensitive).
    private const OPT_OUT_WORDS = ['STOP', 'BAJA', 'CANCELAR', 'NO', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];

    // Respuestas que marcan interés (match exacto). Sirven para que el operador ubique
    // rápido al prospecto en la lista agrupada. No dispara ninguna acción automática:
    // solo etiqueta la respuesta como "Interesado" (el operador da seguimiento a mano).
    private const INTERESTED_WORDS = ['SI', 'INFO', 'INFORMACION'];

    // POST /api/sms/webhook — eventos del gateway (status + inbound)
    public function handle(Request $request): Response
    {
        // Health: registra CUALQUIER llegada al endpoint, ANTES de validar firma.
        // Distingue "el gateway no manda nada" de "manda pero lo rechazamos por firma".
        Setting::set('sms_webhook_last_hit_at', now()->toDateTimeString());

        if (! $this->signatureValid($request)) {
            Setting::set('sms_webhook_last_rejected_at', now()->toDateTimeString());
            Log::warning('SMS webhook signature mismatch');
            return response('Forbidden', 403);
        }

        $event   = $request->input('event');
        $payload = $request->input('payload', []);

        // Último evento procesado con éxito (firma OK).
        Setting::set('sms_webhook_last_at', now()->toDateTimeString());
        Setting::set('sms_webhook_last_event', (string) $event);

        // Se recuperó: marca leídas las alertas de "webhook caído" pendientes.
        AppNotification::where('type', 'sms_webhook_down')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        match ($event) {
            'sms:sent'      => $this->updateStatus($payload, 'sent'),
            'sms:delivered' => $this->updateStatus($payload, 'delivered'),
            'sms:failed'    => $this->handleFailed($payload),
            'sms:received'  => $this->handleInbound($payload),
            default         => Log::info('SMS webhook: evento ignorado', ['event' => $event]),
        };

        return response('EVENT_RECEIVED', 200);
    }

    // Valida el HMAC del gateway. Si no hay secreto configurado (dev/local), no se exige.
    // capcom6 firma HMAC-SHA256 sobre (raw body + X-Timestamp) y manda ambos headers.
    // Además rechazamos timestamps fuera de ±5 min para evitar replays.
    private function signatureValid(Request $request): bool
    {
        $secret = config('sms.webhook_secret');

        if (empty($secret)) {
            return true;
        }

        $signature = $request->header('X-Signature', '');
        $timestamp = $request->header('X-Timestamp', '');

        if (! $timestamp || abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent() . $timestamp, $secret);

        return hash_equals($expected, $signature);
    }

    private function updateStatus(array $payload, string $status): void
    {
        $messageId = $payload['messageId'] ?? null;
        if (! $messageId) {
            return;
        }

        $log = MessageLog::where('channel', 'sms')->where('wa_message_id', $messageId)->first();
        $log?->updateStatus($status);

        // Al entregar, limpiar cualquier notificación de fallo previa de ese número.
        if ($status === 'delivered' && $log) {
            AppNotification::where('type', 'delivery_failed')
                ->where('body', 'like', "%{$log->to_number}%")
                ->delete();
        }

        $this->broadcastCampaignProgress($log);
    }

    private function handleFailed(array $payload): void
    {
        $messageId = $payload['messageId'] ?? null;
        if (! $messageId) {
            return;
        }

        // El gateway manda el porqué en `reason`. Lo persistimos en la fila (no solo al log del
        // server) para que el detalle de la campaña muestre el motivo, no un "-".
        $reason = $payload['reason'] ?? null;

        $log = MessageLog::where('channel', 'sms')->where('wa_message_id', $messageId)->first();
        $log?->update([
            'status'        => 'failed',
            'error_message' => $reason ?: 'El gateway reportó el envío como fallido (sin detalle)',
        ]);

        Log::warning('SMS webhook: envío fallido', [
            'message_id' => $messageId,
            'log_id'     => $log?->id,
            'reason'     => $reason,
        ]);

        // Rebote: a los 3 consecutivos se auto-bloquea el canal SMS (no afecta WhatsApp).
        if ($log) {
            $contact = Contact::where('phone', $log->to_number)->first();
            $contact?->registerSmsBounce();
        }

        $this->broadcastCampaignProgress($log);
    }

    // Progreso en vivo: si el mensaje pertenece a una campaña, avisa al panel para que el
    // modal abierto refresque la fila (Enviado -> Entregado -> Fallido). Sin throttle: el
    // estado terminal del SMS (delivered/failed) no se puede perder. Reusa el evento de campañas.
    private function broadcastCampaignProgress(?MessageLog $log): void
    {
        if ($log?->campaign_id && $campaign = Campaign::find($log->campaign_id)) {
            event(CampaignProgressUpdated::fromCampaign($campaign));
        }
    }

    // Clasifica la respuesta por match exacto: 'opt_out' (baja) tiene prioridad sobre
    // 'interested', y cualquier otra cosa es null. Ignora acentos y mayúsculas.
    private function classifyReply(string $message): ?string
    {
        $word = $this->normalizeWord($message);

        if (in_array($word, self::OPT_OUT_WORDS, true)) {
            return 'opt_out';
        }
        if (in_array($word, self::INTERESTED_WORDS, true)) {
            return 'interested';
        }
        return null;
    }

    // Normaliza a mayúsculas sin acentos para comparar contra las listas de palabras.
    private function normalizeWord(string $message): string
    {
        $upper = mb_strtoupper(trim($message), 'UTF-8');

        return strtr($upper, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
    }

    private function handleInbound(array $payload): void
    {
        // En sms:received el número del remitente viene en `sender` (no `phoneNumber`).
        $from      = $payload['sender'] ?? null;
        $message   = $payload['message'] ?? '';
        $messageId = $payload['messageId'] ?? null;

        if (! $from) {
            return;
        }

        // Dedup: el reconcile (sms:reconcile-received) re-empuja los sms:received por este
        // mismo webhook. Si ya registramos ese id del gateway, no duplicamos ni re-disparamos
        // opt-out/tiempo real. El entrante en vivo también trae el id, así vivo y re-export
        // comparten la llave.
        if ($messageId && SmsInboundMessage::where('gateway_message_id', $messageId)->exists()) {
            return;
        }

        $phone   = Contact::normalizePhone($from);
        $contact = $phone ? Contact::where('phone', $phone)->first() : null;

        // Clasificar la respuesta por texto exacto (baja > interés > nada). El match exacto
        // evita falsos positivos: "no me interesa" no dispara baja, solo "NO" sola.
        $action = $this->classifyReply($message);

        if ($action === 'opt_out' && $contact) {
            $contact->smsOptOut();
            Log::info("SMS opt-out por texto '{$message}' - contacto {$contact->id}");
        }

        // Registrar SIEMPRE en la bandeja plana de respuestas (aunque el número no esté en contactos).
        SmsInboundMessage::create([
            'gateway_message_id' => $messageId,
            'contact_id'         => $contact?->id,
            'from_number'        => $phone ?? $from,
            'body'               => $message,
            'action'             => $action,
            'received_at'        => now(),
        ]);

        // Tiempo real: solo si el remitente ES un contacto (evita spam de SMS de operadoras/servicios,
        // ej. UNOTV/TELCEL, que no son respuestas de campana). El panel lo escucha por WebSocket.
        if ($contact) {
            event(new InboundMessageReceived(
                contactId:   $contact->id,
                contactName: $contact->name,
                body:        $message,
                channel:     'sms',
                receivedAt:  now()->setTimezone('America/Mexico_City')->format('Y-m-d H:i'),
            ));
        }
    }
}
