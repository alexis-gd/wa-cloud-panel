<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Único punto de salida HTTP hacia el gateway SMS (SMS Gateway for Android™, capcom6).
 * Espejo de WhatsAppClient: todos los envíos SMS deben pasar por aquí (regla seguridad #1
 * extendida al canal SMS — nunca Http:: suelto en jobs/controllers).
 */
class SmsGatewayClient
{
    private string $baseUrl;
    private ?string $login;
    private ?string $password;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrl  = rtrim((string) config('sms.gateway.url'), '/');
        $this->login    = config('sms.gateway.login');
        $this->password = config('sms.gateway.password');
        $this->timeout  = (int) config('sms.gateway.timeout', 15);
    }

    /**
     * Envía un SMS a un número (E.164). El pool de chips lo resuelve el gateway.
     *
     * @return array{ok: bool, status: int, message_id: ?string, error: mixed}
     */
    public function send(string $to, string $body): array
    {
        $response = Http::withBasicAuth((string) $this->login, (string) $this->password)
            ->timeout($this->timeout)
            ->post("{$this->baseUrl}/messages", [
                'message'      => $body,
                'phoneNumbers' => [$this->toE164($to)],
            ]);

        $json = $response->json() ?? [];

        if ($response->failed()) {
            Log::error('SMS gateway error', [
                'status' => $response->status(),
                'body'   => $json,
                'to'     => substr($to, -4), // nunca loguear el número completo
            ]);

            return [
                'ok'         => false,
                'status'     => $response->status(),
                'message_id' => null,
                'error'      => $json ?: ['message' => 'gateway request failed'],
            ];
        }

        return [
            'ok'         => true,
            'status'     => $response->status(),
            'message_id' => $json['id'] ?? null,
            'error'      => null,
        ];
    }

    /**
     * Consulta el estado de un mensaje ya enviado (server-a-server, sin depender del webhook).
     * GET {baseUrl}/messages/{id}. Sirve para reconciliar entregas cuando el webhook del
     * teléfono no llega. capcom6 devuelve un `state` de mensaje y por destinatario:
     * Pending | Processed | Sent | Delivered | Failed.
     *
     * @return array{ok: bool, state: ?string, error: mixed}
     */
    public function getState(string $messageId): array
    {
        $response = Http::withBasicAuth((string) $this->login, (string) $this->password)
            ->timeout($this->timeout)
            ->get("{$this->baseUrl}/messages/{$messageId}");

        if ($response->failed()) {
            return ['ok' => false, 'state' => null, 'error' => $response->json() ?: 'gateway request failed'];
        }

        $json  = $response->json() ?? [];
        // Estado a nivel mensaje; si no viene, el del primer destinatario.
        $state = $json['state'] ?? ($json['recipients'][0]['state'] ?? null);

        return [
            'ok'    => true,
            'state' => $state,
            'error' => $json['recipients'][0]['error'] ?? null,
        ];
    }

    /**
     * Pide al gateway re-exportar los SMS entrantes (sms:received) de una ventana.
     * A diferencia del estado saliente (getState, pull directo), los entrantes viven en el
     * TELÉFONO: capcom6 no los expone para leer server-a-server, solo puede re-empujarlos por
     * el webhook. Este endpoint le ordena al device volver a enviar los recibidos del periodo;
     * llegan por el mismo POST /api/sms/webhook y el dedup por gateway_message_id evita repetir.
     * Es la red de seguridad si MIUI mató la app y se perdieron entrantes en vivo.
     *
     * POST {baseUrl}/messages/inbox/export  body {since, until, deviceId?} (ISO8601). Respuesta 202.
     * Async: solo dispara la exportación; no devuelve los mensajes.
     *
     * @return array{ok: bool, status: int, error: mixed}
     */
    public function requestInboxExport(string $since, string $until, ?string $deviceId = null): array
    {
        $body = ['since' => $since, 'until' => $until];
        if ($deviceId) {
            $body['deviceId'] = $deviceId;
        }

        $response = Http::withBasicAuth((string) $this->login, (string) $this->password)
            ->timeout($this->timeout)
            ->post("{$this->baseUrl}/messages/inbox/export", $body);

        if ($response->failed()) {
            Log::error('SMS gateway inbox export error', [
                'status' => $response->status(),
                'body'   => $response->json() ?: 'gateway request failed',
            ]);

            return ['ok' => false, 'status' => $response->status(), 'error' => $response->json() ?: 'gateway request failed'];
        }

        return ['ok' => true, 'status' => $response->status(), 'error' => null];
    }

    /**
     * El gateway exige el número en E.164 CON prefijo '+' (ej. +529231311146).
     * En BD los guardamos como 52XXXXXXXXXX (sin '+'), así que lo anteponemos aquí,
     * en el único punto de salida — el operador nunca escribe el '+' ni el código de país.
     * Sin esto el gateway rechaza el envío ("invalid phone number").
     */
    private function toE164(string $phone): string
    {
        return '+' . ltrim($phone, '+');
    }
}
