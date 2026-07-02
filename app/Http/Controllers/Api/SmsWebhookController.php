<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\Contact;
use App\Models\MessageLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Webhook del gateway SMS (SMS Gateway for Android™, capcom6).
 *
 * A diferencia de Twilio, el gateway Android NO expone códigos de carrier (21610, 30004…).
 * Sus eventos son sms:sent | sms:delivered | sms:failed | sms:received. El mapeo a la lógica
 * de auto-protección (contexto-twilio-sms) es:
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

    // POST /api/sms/webhook — eventos del gateway (status + inbound)
    public function handle(Request $request): Response
    {
        if (! $this->signatureValid($request)) {
            Log::warning('SMS webhook signature mismatch');
            return response('Forbidden', 403);
        }

        $event   = $request->input('event');
        $payload = $request->input('payload', []);

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
    private function signatureValid(Request $request): bool
    {
        $secret = config('sms.webhook_secret');

        if (empty($secret)) {
            return true;
        }

        $signature = $request->header('X-Signature', '');
        $expected  = hash_hmac('sha256', $request->getContent(), $secret);

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
    }

    private function handleFailed(array $payload): void
    {
        $messageId = $payload['messageId'] ?? null;
        if (! $messageId) {
            return;
        }

        $log = MessageLog::where('channel', 'sms')->where('wa_message_id', $messageId)->first();
        $log?->updateStatus('failed');

        Log::warning('SMS webhook: envío fallido', [
            'message_id' => $messageId,
            'log_id'     => $log?->id,
            'reason'     => $payload['reason'] ?? null,
        ]);

        // Rebote: a los 3 consecutivos se auto-bloquea el canal SMS (no afecta WhatsApp).
        if ($log) {
            $contact = Contact::where('phone', $log->to_number)->first();
            $contact?->registerSmsBounce();
        }
    }

    private function handleInbound(array $payload): void
    {
        $from    = $payload['phoneNumber'] ?? null;
        $message = $payload['message'] ?? '';

        if (! $from) {
            return;
        }

        $phone   = Contact::normalizePhone($from);
        $contact = $phone ? Contact::where('phone', $phone)->first() : null;

        if (! $contact) {
            Log::info('SMS webhook inbound: contacto no encontrado', ['from' => substr($from, -4)]);
            return;
        }

        // Opt-out SMS por texto exacto (evita falsos positivos: "no me interesa" no dispara, "NO" sí).
        if (in_array(strtoupper(trim($message)), self::OPT_OUT_WORDS, true)) {
            $contact->smsOptOut();
            Log::info("SMS opt-out por texto '{$message}' — contacto {$contact->id}");
        }
    }
}
