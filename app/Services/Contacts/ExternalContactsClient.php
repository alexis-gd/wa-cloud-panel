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
    /** Token vigente de esta corrida (modo `login`). No se persiste: caduca en minutos. */
    private ?string $token = null;

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

        // Modo `login`: primero el token, luego la consulta. Si el login falla no tiene
        // caso pedir los datos: se devuelve el error del login, que es el que explica qué pasó.
        if (config('contact_sync.auth', 'login') === 'login') {
            $sesion = $this->login();

            if (! $sesion['ok']) {
                return ['ok' => false, 'status' => 0, 'body' => null, 'error' => $sesion['error']];
            }

            $this->token = $sesion['token'];
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

    /**
     * Pide un token al endpoint de login. Solo aplica al modo `login`.
     *
     * @return array{ok: bool, token: ?string, error: ?string}
     */
    public function login(): array
    {
        $url = config('contact_sync.login_url');

        if (empty($url)) {
            return ['ok' => false, 'token' => null, 'error' => 'Falta SYNC_API_LOGIN_URL en el .env.'];
        }

        try {
            $response = Http::timeout($this->timeout ?? (int) config('contact_sync.timeout', 30))
                ->acceptJson()
                ->post($url, [
                    (string) config('contact_sync.login_user_key', 'usuario') => config('contact_sync.user'),
                    (string) config('contact_sync.login_pass_key', 'password') => config('contact_sync.password'),
                ]);
        } catch (\Throwable $e) {
            Log::error('API de contactos: no se pudo hacer login', ['error' => $e->getMessage()]);

            return ['ok' => false, 'token' => null, 'error' => $e->getMessage()];
        }

        if ($response->failed()) {
            // Nunca se loguea la contraseña ni el token: solo el código.
            Log::error('API de contactos: login rechazado', ['status' => $response->status()]);

            return [
                'ok'    => false,
                'token' => null,
                'error' => "El login respondió HTTP {$response->status()}. Revisa SYNC_API_USER y SYNC_API_PASSWORD.",
            ];
        }

        $token = data_get($response->json(), (string) config('contact_sync.token_path', 'token'));

        if (empty($token) || ! is_string($token)) {
            return [
                'ok'    => false,
                'token' => null,
                'error' => 'El login respondió OK pero no traía token en "'
                    . config('contact_sync.token_path', 'token') . '". Ajusta SYNC_API_TOKEN_PATH.',
            ];
        }

        return ['ok' => true, 'token' => $token, 'error' => null];
    }

    /** Arma la petición con el modo de autenticación configurado. */
    private function request()
    {
        $http = Http::timeout($this->timeout ?? (int) config('contact_sync.timeout', 30))
            ->acceptJson();

        return match (config('contact_sync.auth', 'login')) {
            // El token se pide al vuelo: el de este API caduca en 5 minutos, así que
            // guardarlo entre corridas no serviría de nada.
            'login'  => $http->withToken((string) ($this->token ?? '')),
            'bearer' => $http->withToken((string) config('contact_sync.token')),
            'none'   => $http,
            default  => $http->withBasicAuth(
                (string) config('contact_sync.user'),
                (string) config('contact_sync.password')
            ),
        };
    }
}
