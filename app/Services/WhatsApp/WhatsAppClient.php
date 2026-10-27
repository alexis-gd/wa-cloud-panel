<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppClient
{
    private string $baseUrl;

    public function __construct()
    {
        $version = config('services.whatsapp.api_version');
        $this->baseUrl = "https://graph.facebook.com/{$version}";
    }

    /**
     * GET a cualquier recurso de la Graph API.
     * Usado para consultar calidad del número, estado de cuenta, etc.
     */
    public function get(string $path, string $token, array $query = []): array
    {
        $url = "{$this->baseUrl}/{$path}";

        $response = Http::withToken($token)
            ->timeout(10)
            ->get($url, $query);

        return [
            'status' => $response->status(),
            'body'   => $response->json() ?? [],
            'ok'     => $response->successful(),
        ];
    }

    /**
     * Único punto de salida HTTP hacia la API de Meta.
     * Todos los envíos deben pasar por aquí.
     */
    public function post(string $phoneNumberId, string $token, array $payload): array
    {
        $url = "{$this->baseUrl}/{$phoneNumberId}/messages";

        $response = Http::withToken($token)
            ->timeout(15)
            ->post($url, $payload);

        if ($response->failed()) {
            Log::error('WhatsApp API error', [
                'status'  => $response->status(),
                'body'    => $response->json(),
                'payload' => $payload,
            ]);
        }

        return [
            'status' => $response->status(),
            'body'   => $response->json() ?? [],
            'ok'     => $response->successful(),
        ];
    }

    /**
     * Descarga el binario de un archivo que mando un contacto.
     *
     * Son DOS llamadas porque asi lo expone Meta: primero se pregunta por el id del archivo y
     * contesta con una URL temporal, y luego se baja de ahi. Esa URL **caduca en minutos** y
     * exige el mismo token en la cabecera, asi que no sirve para guardarla ni para mandarsela
     * al navegador del operador: hay que bajar el archivo ahora y servirlo nosotros.
     *
     * Timeout largo a proposito: un video de 16 MB por la red del servidor no baja en 10
     * segundos, y el timeout corto del resto del cliente lo cortaria a la mitad.
     *
     * @return array{ok: bool, body: ?string, mime: ?string, size: ?int, error: ?string}
     */
    public function downloadMedia(string $mediaId, string $token): array
    {
        $meta = $this->get($mediaId, $token);

        if (! $meta['ok']) {
            return $this->fallaDeMedia('Meta no devolvio los datos del archivo', [
                'media_id' => $mediaId,
                'status'   => $meta['status'],
            ]);
        }

        $url = $meta['body']['url'] ?? null;

        if (! $url) {
            return $this->fallaDeMedia('La respuesta de Meta no traia URL de descarga', [
                'media_id' => $mediaId,
            ]);
        }

        try {
            // `withToken` es obligatorio tambien aqui: la URL de Meta no es publica, responde
            // 401 sin la cabecera aunque la tengas completa.
            $response = Http::withToken($token)->timeout(60)->get($url);
        } catch (\Throwable $e) {
            return $this->fallaDeMedia('No se pudo descargar el archivo', [
                'media_id' => $mediaId,
                'error'    => $e->getMessage(),
            ]);
        }

        if ($response->failed()) {
            return $this->fallaDeMedia('La descarga respondio con error', [
                'media_id' => $mediaId,
                'status'   => $response->status(),
            ]);
        }

        return [
            'ok'    => true,
            'body'  => $response->body(),
            'mime'  => $meta['body']['mime_type'] ?? $response->header('Content-Type'),
            'size'  => isset($meta['body']['file_size']) ? (int) $meta['body']['file_size'] : null,
            'error' => null,
        ];
    }

    /**
     * Sube un archivo a Meta y devuelve su id, que es lo que se manda luego en el mensaje.
     *
     * No se puede mandar un archivo adjunto de golpe: Meta exige subirlo primero y despues
     * enviar un mensaje que lo referencia por id. El id dura 30 dias, asi que reenviar el
     * mismo archivo a otro contacto no vuelve a gastar la subida.
     *
     * @return array{ok: bool, media_id: ?string, error: ?string}
     */
    public function uploadMedia(string $phoneNumberId, string $token, string $rutaLocal, string $mime): array
    {
        $url = "{$this->baseUrl}/{$phoneNumberId}/media";

        try {
            $response = Http::withToken($token)
                ->timeout(60)
                ->attach('file', file_get_contents($rutaLocal), basename($rutaLocal), ['Content-Type' => $mime])
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'type'              => $mime,
                ]);
        } catch (\Throwable $e) {
            Log::error('WhatsApp: no se pudo subir el archivo', ['error' => $e->getMessage()]);

            return ['ok' => false, 'media_id' => null, 'error' => $e->getMessage()];
        }

        if ($response->failed()) {
            Log::error('WhatsApp: subida de archivo rechazada', [
                'status' => $response->status(),
                'body'   => $response->json(),
            ]);

            return [
                'ok'       => false,
                'media_id' => null,
                'error'    => data_get($response->json(), 'error.message', 'Meta rechazo el archivo'),
            ];
        }

        return ['ok' => true, 'media_id' => $response->json('id'), 'error' => null];
    }

    /** Registra el fallo y devuelve la forma de error de `downloadMedia`. */
    private function fallaDeMedia(string $mensaje, array $contexto): array
    {
        Log::error("WhatsApp: {$mensaje}", $contexto);

        return ['ok' => false, 'body' => null, 'mime' => null, 'size' => null, 'error' => $mensaje];
    }
}
