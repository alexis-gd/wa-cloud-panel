<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\WhatsApp\MediaStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Entrega los archivos de las conversaciones.
 *
 * Va en su propio controlador (no en `ConversationController`) porque es la unica ruta del
 * panel que no responde JSON: devuelve el binario. Por aqui pasan fotos de INE y comprobantes
 * de domicilio, asi que la ruta hace tres cosas antes de soltar un byte.
 *
 * 1. **Exige sesion.** Los archivos viven en el disco privado, nunca en `public/`. No hay URL
 *    que funcione sin estar dentro del panel - ni firmada ni temporal: una URL firmada que se
 *    copia o se queda en el log de nginx abre el archivo a quien la tenga.
 *
 * 2. **Respeta lo del agente.** La misma regla de la lista y del detalle: un agente solo abre
 *    los archivos de las conversaciones que tiene asignadas (ver `Contact::scopeAsignadoA`).
 *
 * 3. **No deja que el archivo se ejecute en nuestro dominio.** El contenido lo eligio un
 *    desconocido. Ver `debeDescargarse()`.
 */
class ConversationMediaController extends Controller
{
    /**
     * Tipos que el navegador puede mostrar sin riesgo de ejecutar codigo nuestro.
     *
     * Es una lista blanca a proposito. Un `image/svg+xml` o un `text/html` que un contacto
     * manda como documento SI ejecutan JavaScript en nuestro dominio si se abren en linea, y
     * ahi vive el token de sesion del operador. Todo lo que no este aqui se descarga en vez de
     * abrirse. El PDF se queda: el operador necesita ver el comprobante, y el visor del
     * navegador lo corre aislado.
     */
    private const INLINE = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'application/pdf',
        'audio/ogg',
        'audio/mpeg',
        'audio/mp4',
        'audio/aac',
        'audio/amr',
        'video/mp4',
        'video/3gpp',
    ];

    public function __construct(private readonly MediaStore $store)
    {
    }

    // GET /api/conversations/media/{conversationId}
    public function show(Request $request, int $conversationId): BinaryFileResponse|JsonResponse
    {
        $conversation = Conversation::with('contact')->find($conversationId);

        // 404 y no 403 cuando no le toca: un 403 confirmaria que ese mensaje existe.
        if (! $conversation
            || ! $conversation->tieneArchivo()
            || ! $conversation->contact
            || ! $conversation->contact->esVisibleEnConversacionesPara($request->user())) {
            return $this->noEncontrado();
        }

        // Todavia en la cola. No es un error del operador y no debe verse como tal: la burbuja
        // dice "descargando" y se arregla sola cuando el job termina.
        if ($conversation->media_status === 'pending') {
            return response()->json([
                'status'  => 'error',
                'message' => 'El archivo se está descargando. Espera unos segundos.',
                'code'    => 'MEDIA_NOT_READY',
            ], 422);
        }

        $ruta = $this->store->rutaAbsoluta($conversation->media_path);

        // `failed`, o el archivo ya no esta en disco (borrado por retencion). Mismo mensaje:
        // al operador le sirve lo mismo saber que no lo va a poder ver.
        if (! $conversation->archivoListo() || ! $ruta) {
            return response()->json([
                'status'  => 'error',
                'message' => 'El archivo no está disponible. Pídele al contacto que lo reenvíe.',
                'code'    => 'MEDIA_UNAVAILABLE',
            ], 422);
        }

        $mime = $conversation->media_mime ?: 'application/octet-stream';

        $response = response()->file($ruta, [
            'Content-Type' => $mime,
            // Sin esto el navegador adivina el tipo por el contenido: un archivo guardado como
            // octet-stream pero que empieza con HTML se interpretaria como pagina.
            'X-Content-Type-Options' => 'nosniff',
            // Son datos personales: que no queden en cache de proxies ni del navegador.
            'Cache-Control' => 'private, no-store',
        ]);

        // `setContentDisposition` y no un encabezado armado a mano: el nombre lo escribio el
        // contacto, y Symfony es quien sabe citarlo y codificarlo (acentos incluidos) sin que
        // se pueda romper el encabezado desde adentro.
        $response->setContentDisposition(
            in_array($this->mimeBase($mime), self::INLINE, true) ? 'inline' : 'attachment',
            $this->nombreSeguro($conversation),
        );

        return $response;
    }

    private function noEncontrado(): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => 'Archivo no encontrado.',
        ], 404);
    }

    /** El mime puede traer parametros: "audio/ogg; codecs=opus". */
    private function mimeBase(string $mime): string
    {
        return strtolower(trim(explode(';', $mime)[0]));
    }

    /**
     * Deja el nombre del archivo en algo que no pueda romper el encabezado de respuesta.
     *
     * Se quitan los caracteres de control (el salto de linea entre ellos, que es el que permite
     * inyectar encabezados), las comillas, las diagonales y el `%`. Los acentos se quedan: de
     * codificarlos se encarga Symfony.
     *
     * La primera version de esto usaba una lista blanca `[^\w\s.\-()]`, y `\s` incluye el
     * salto de linea - o sea que dejaba pasar justo lo que queria bloquear.
     */
    private function nombreSeguro(Conversation $conversation): string
    {
        $nombre = $conversation->media_filename ?: 'archivo-' . $conversation->id;

        $limpio = preg_replace('#[\x00-\x1F\x7F"\\/%]+#u', '', basename($nombre));

        $limpio = trim((string) $limpio) ?: ('archivo-' . $conversation->id);

        // Sin extension el navegador la adivina del mime. En Windows `image/jpeg` esta
        // asociado a `.jfif` en el registro, asi que una foto se guardaba como `Foto.jfif`
        // y varios programas no la abren. Solo los documentos traen nombre propio desde
        // WhatsApp; una foto o una nota de voz llegan sin nombre y caian justo en ese hueco.
        if (! preg_match('/\.[A-Za-z0-9]{2,5}$/', $limpio)) {
            $extension = MediaStore::extensionDe($conversation->media_mime);

            if ($extension !== null) {
                $limpio .= '.' . $extension;
            }
        }

        return $limpio;
    }
}
