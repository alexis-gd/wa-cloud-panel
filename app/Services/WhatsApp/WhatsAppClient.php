<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppClient
{
    /**
     * Tope de tamano de un archivo que nos manda un contacto.
     *
     * Es el mismo de Meta para documentos (su limite mas alto), a proposito: asi nunca
     * rechazamos algo que Meta si acepto. Con la descarga en streaming el tamano ya no es un
     * riesgo de memoria, entonces el tope solo existe como red por si Meta cambia su limite.
     */
    public const MAX_MEDIA_BYTES = 100 * 1024 * 1024;
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
     * POST a un endpoint de administracion de Meta (plantillas, numeros).
     *
     * Existe porque `post()` esta clavado a `/{phone_number_id}/messages` - es el metodo de
     * ENVIAR. Lo de administracion cuelga de la WABA, no del numero, y lleva otra ruta. La
     * regla de seguridad es que todo el HTTP a Meta pase por este cliente, no que haya un solo
     * metodo: asi el logueo y el manejo de errores siguen siendo uno.
     *
     * @param  string  $path  Ruta sin la version, p.ej. "{waba_id}/message_templates".
     */
    public function postPath(string $path, string $token, array $payload): array
    {
        $url = "{$this->baseUrl}/{$path}";

        $response = Http::withToken($token)->timeout(30)->post($url, $payload);

        if ($response->failed()) {
            Log::error('WhatsApp API error (administracion)', [
                'path'    => $path,
                'status'  => $response->status(),
                'body'    => $response->json(),
                // El payload NO se loguea completo a proposito: en otros endpoints puede traer
                // datos del contacto. Lo que hace falta para diagnosticar es la respuesta.
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
     * @return array{ok: bool, path: ?string, mime: ?string, size: ?int, error: ?string}
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

        $tamano = isset($meta['body']['file_size']) ? (int) $meta['body']['file_size'] : null;

        // El tope se revisa con la ficha, ANTES de pedir el archivo: Meta ya nos dijo cuanto
        // pesa, asi que rechazar algo monstruoso no cuesta ni ancho de banda ni tiempo.
        if ($tamano !== null && $tamano > self::MAX_MEDIA_BYTES) {
            return $this->fallaDeMedia('El archivo pasa del tamano maximo', [
                'media_id' => $mediaId,
                'size'     => $tamano,
                'max'      => self::MAX_MEDIA_BYTES,
            ]);
        }

        $destino = $this->rutaTemporal($mediaId);

        try {
            // `sink` escribe la respuesta DIRECTO al archivo, en pedazos. Antes esto hacia
            // `$response->body()`, que arma el archivo completo en una variable de PHP: un
            // documento de 100 MB eran 100 MB de memoria en el worker, y si rebasaba el
            // `memory_limit` el proceso moria. Asi la memoria no depende del tamano.
            //
            // `withToken` es obligatorio tambien aqui: la URL de Meta no es publica, responde
            // 401 sin la cabecera aunque la tengas completa.
            $response = Http::withToken($token)->timeout(120)->sink($destino)->get($url);
        } catch (\Throwable $e) {
            $this->borrarTemporal($destino);

            return $this->fallaDeMedia('No se pudo descargar el archivo', [
                'media_id' => $mediaId,
                'error'    => $e->getMessage(),
            ]);
        }

        if ($response->failed()) {
            // Con `sink` el cuerpo del error tambien se escribio en el archivo: hay que
            // tirarlo o quedaria un archivo con un JSON de error adentro.
            $this->borrarTemporal($destino);

            return $this->fallaDeMedia('La descarga respondio con error', [
                'media_id' => $mediaId,
                'status'   => $response->status(),
            ]);
        }

        return [
            'ok'    => true,
            // Ruta local del archivo ya en disco. Quien lo reciba se queda a cargo de moverlo
            // a su lugar definitivo o de borrarlo.
            'path'  => $destino,
            'mime'  => $meta['body']['mime_type'] ?? $response->header('Content-Type'),
            // El tamano real en disco, no el que dijo Meta: si la descarga se corto a medias,
            // el de la ficha mentiria.
            'size'  => is_file($destino) ? filesize($destino) : $tamano,
            'error' => null,
        ];
    }

    /**
     * Archivo temporal donde cae la descarga.
     *
     * Vive bajo `storage/app` y no en el temporal del sistema a proposito: ahi mismo esta su
     * destino final, y mover un archivo dentro del mismo sistema de archivos es instantaneo
     * (solo cambia el nombre). Entre dos discos distintos, PHP tiene que copiar byte por byte.
     */
    private function rutaTemporal(string $mediaId): string
    {
        $carpeta = storage_path('app/tmp/media');

        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0775, true);
        }

        // El id de Meta no se usa como nombre: viene de afuera. Un uuid nuestro y listo.
        return $carpeta . DIRECTORY_SEPARATOR . Str::uuid() . '.part';
    }

    private function borrarTemporal(string $ruta): void
    {
        if (is_file($ruta)) {
            @unlink($ruta);
        }
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
            // `fopen` y no `file_get_contents`: el archivo viaja leyendose de disco, no cargado
            // entero en memoria. Con imagenes no se notaba (ya vienen reducidas), pero un video
            // de 16 MB o un documento de 20 MB por cada envio si. Es la misma razon por la que
            // la descarga usa `sink()`.
            $flujo = fopen($rutaLocal, 'r');

            if ($flujo === false) {
                return ['ok' => false, 'media_id' => null, 'error' => 'No se pudo leer el archivo'];
            }

            $response = Http::withToken($token)
                ->timeout(120)
                ->attach('file', $flujo, basename($rutaLocal), ['Content-Type' => $mime])
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

        return ['ok' => false, 'path' => null, 'mime' => null, 'size' => null, 'error' => $mensaje];
    }
}
