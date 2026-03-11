<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageLog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
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
        Log::info('Webhook received', $body);

        // Procesar cambios de status (delivered, read, failed)
        foreach (data_get($body, 'entry.*.changes.*.value.statuses', []) as $statusEvent) {
            $waMessageId = $statusEvent['id']   ?? null;
            $status      = $statusEvent['status'] ?? null;

            if ($waMessageId && $status) {
                $log = MessageLog::where('wa_message_id', $waMessageId)->first();
                $log?->updateStatus($status);
            }
        }

        return response('EVENT_RECEIVED', 200);
    }
}
