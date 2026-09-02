<?php

namespace App\Services\Contacts;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Único punto de salida HTTP hacia el API de contactos del cliente.
 *
 * Mismo patrón que WhatsAppClient y SmsGatewayClient: nadie llama a ese API con `Http::`
 * suelto desde un comando o un controller. Todo pasa por aquí, que es donde viven el
 * timeout, la autenticación y el logging.
 *
 * El API es del cliente, no nuestro: corre en su red (192.168.17.20) sobre una VM Windows.
 * Todo lo que puede cambiar vive en `config/contact_sync.php`.
 */
class ExternalContactsClient
{
    public function __construct(
        private readonly ?string $url = null,
        private readonly ?int $timeout = null,
    ) {
    }

    public function configurado(): bool
    {
        return ! empty($this->url ?? config('contact_sync.url'));
    }

    /**
     * Trae la respuesta cruda del API, sin interpretarla.
     *
     * Devuelve siempre la misma forma, con error o sin él: quien llama decide qué hacer.
     * No lanza excepciones para que un API caído no reviente el cron de la madrugada.
     *
     * @return array{ok: bool, status: int, body: mixed, error: ?string}
     */
    public function fetch(): array
    {
        $url = $this->url ?? config('contact_sync.url');

        if (empty($url)) {
            return [
                'ok'     => false,
                'status' => 0,
                'body'   => null,
                'error'  => 'Falta SYNC_API_URL en el .env: no se sabe a dónde consultar.',
            ];
        }

        try {
            $response = $this->request()->get($url);
        } catch (\Throwable $e) {
            // Timeout, DNS, conexión rechazada, firewall. El mensaje del cliente HTTP es
            // más útil que un stack trace para diagnosticar red.
            Log::error('API de contactos: no se pudo conectar', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $e->getMessage()];
        }

        if ($response->failed()) {
            Log::error('API de contactos: respuesta con error', [
                'url'    => $url,
                'status' => $response->status(),
            ]);

            return [
                'ok'     => false,
                'status' => $response->status(),
                'body'   => $response->json() ?? $response->body(),
                'error'  => "El API respondió HTTP {$response->status()}.",
            ];
        }

        $json = $response->json();

        if ($json === null) {
            return [
                'ok'     => false,
                'status' => $response->status(),
                'body'   => $response->body(),
                'error'  => 'La respuesta no es JSON válido.',
            ];
        }

        return ['ok' => true, 'status' => $response->status(), 'body' => $json, 'error' => null];
    }

    /** Arma la petición con el modo de autenticación configurado. */
    private function request()
    {
        $http = Http::timeout($this->timeout ?? (int) config('contact_sync.timeout', 30))
            ->acceptJson();

        return match (config('contact_sync.auth', 'basic')) {
            'bearer' => $http->withToken((string) config('contact_sync.token')),
            'none'   => $http,
            default  => $http->withBasicAuth(
                (string) config('contact_sync.user'),
                (string) config('contact_sync.password')
            ),
        };
    }
}
