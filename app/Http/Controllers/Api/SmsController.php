<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Services\Sms\SmsGatewayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsController extends Controller
{
    public function __construct(private readonly SmsGatewayClient $client) {}

    // POST /api/sms/send-test
    // Envía un SMS suelto (sin campaña) para probar el gateway. Solo admin.
    // No aplica dedup/cooldown: es una prueba deliberada del operador técnico.
    public function sendTest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to'   => 'required|string',
            'body' => 'required|string|max:1000',
        ]);

        $to = Contact::normalizePhone($data['to']);

        if (! $to) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Número inválido. Usa formato mexicano (10 dígitos o 52XXXXXXXXXX).',
                'code'    => 'INVALID_NUMBER',
            ], 422);
        }

        // Regla: crear log ANTES de llamar al gateway (igual que envíos reales).
        $log      = MessageLog::logSmsSend($to, $data['body']);
        $response = $this->client->send($to, $data['body']);

        $log->updateFromSmsResponse($response);

        return response()->json([
            'status'  => $response['ok'] ? 'ok' : 'error',
            'data'    => [
                'log_id'     => $log->id,
                'sms_status' => $log->fresh()->status,
                'message_id' => $response['message_id'] ?? null,
            ],
            'message' => $response['ok']
                ? "SMS de prueba enviado a {$to}."
                : 'El gateway rechazó el envío. Revisa la configuración del gateway.',
        ], $response['ok'] ? 200 : 422);
    }
}
