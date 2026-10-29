<?php

namespace App\Http\Controllers\Api;

use App\Events\ConversationUpdated;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Services\WhatsApp\ImageForWhatsApp;
use App\Services\WhatsApp\MediaStore;
use App\Services\WhatsApp\OutboundGuard;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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

        $enLinea = MediaStore::seMuestraEnLinea($mime);

        $response = response()->file($ruta, [
            'Content-Type' => $mime,
            // Sin esto el navegador adivina el tipo por el contenido: un archivo guardado como
            // octet-stream pero que empieza con HTML se interpretaria como pagina.
            'X-Content-Type-Options' => 'nosniff',
            // Son datos personales: que no queden en cache de proxies ni del navegador.
            'Cache-Control' => 'private, no-store',
            // El panel NO navega a esta URL: baja el archivo con `fetch` y lo envuelve en una
            // URL `blob:`. Un `blob:` hereda NUESTRO origen, asi que abrir ahi un SVG o un
            // HTML ejecutaria su JavaScript en el dominio del panel - justo lo que el
            // `attachment` de abajo evita en una navegacion directa, pero que no alcanza a
            // cubrir por ese camino. Este encabezado le pasa la misma decision al panel.
            'X-Media-Disposition' => $enLinea ? 'inline' : 'attachment',
        ]);

        // `setContentDisposition` y no un encabezado armado a mano: el nombre lo escribio el
        // contacto, y Symfony es quien sabe citarlo y codificarlo (acentos incluidos) sin que
        // se pueda romper el encabezado desde adentro.
        $response->setContentDisposition(
            $enLinea ? 'inline' : 'attachment',
            $this->nombreSeguro($conversation),
        );

        return $response;
    }

    /**
     * Manda una imagen al contacto desde Conversaciones.
     * POST /api/conversations/{contactId}/media
     *
     * Pasa por el MISMO guardia que el texto (`OutboundGuard`): una foto a alguien que se dio
     * de baja es igual de grave que un texto. Y por `ImageForWhatsApp`, porque el tope de Meta
     * para imagen es de 5 MB y una foto de telefono pesa el doble o el triple.
     *
     * El envio es sincrono, no por cola: el operador esta mirando el chat y necesita saber si
     * salio o no. Un job le daria un "listo" que todavia no es cierto.
     */
    public function store(
        Request $request,
        int $contactId,
        WhatsAppClient $client,
        OutboundGuard $guard,
        ImageForWhatsApp $imagenes,
    ): JsonResponse {
        $archivo = $request->file('file');

        // PHP descarta el archivo ANTES de que Laravel lo valide si supera `upload_max_filesize`.
        // Sin esto la respuesta seria "elige un archivo", que despista: el operador si eligio uno.
        if ($archivo !== null
            && ! $archivo->isValid()
            && in_array($archivo->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            return $this->error(
                'La imagen supera el límite de subida del servidor (' . ini_get('upload_max_filesize')
                    . '). Usa una más ligera o pide a soporte que suba ese límite.',
                'UPLOAD_LIMIT_EXCEEDED',
            );
        }

        // Pasarse de `post_max_size` es distinto y peor: PHP tira la peticion ENTERA, no solo
        // el archivo, asi que no hay ni error de subida que mirar. La validacion diria "el
        // campo file es obligatorio", que al operador no le dice nada: el si eligio un archivo.
        if ($archivo === null && $this->superaElTamanoDePeticion($request)) {
            return $this->error(
                'La imagen es demasiado pesada para el servidor (máximo ' . ini_get('post_max_size')
                    . '). Usa una más ligera o pide a soporte que suba ese límite.',
                'UPLOAD_LIMIT_EXCEEDED',
            );
        }

        $request->validate([
            'file'    => 'required|file|mimetypes:image/jpeg,image/png,image/webp',
            'caption' => 'nullable|string|max:1024',
        ]);

        $contact = Contact::findOrFail($contactId);

        // Misma regla que para leer el chat: un agente solo atiende lo suyo. Se responde 404 y
        // no 403 para no confirmar que ese contacto existe.
        if (! $contact->esVisibleEnConversacionesPara($request->user())) {
            return $this->noEncontrado();
        }

        $permiso = $guard->revisar($contact);

        if (! $permiso['ok']) {
            return $this->error($permiso['message'], $permiso['code'], $permiso['status']);
        }

        $listo = $imagenes->preparar($archivo->getRealPath(), $archivo->getMimeType());

        if (! $listo['ok']) {
            return $this->error($listo['error'], 'IMAGE_NOT_SUPPORTED');
        }

        $phone  = $permiso['phone'];
        $subida = $client->uploadMedia($phone->phone_number_id, $phone->token, $listo['path'], $listo['mime']);

        if (! $subida['ok']) {
            $this->limpiar($listo);

            return $this->error('WhatsApp no aceptó la imagen: ' . $subida['error'], 'UPLOAD_REJECTED');
        }

        $caption = $request->input('caption');

        $envio = $client->post($phone->phone_number_id, $phone->token, array_filter([
            'messaging_product' => 'whatsapp',
            'to'                => $contact->phone,
            'type'              => 'image',
            'image'             => array_filter([
                'id'      => $subida['media_id'],
                'caption' => $caption,
            ]),
        ]));

        if (! $envio['ok']) {
            $this->limpiar($listo);
            Log::error('Envio de imagen rechazado', [
                'contact_id' => $contactId,
                'response'   => $envio['body'],
            ]);

            return $this->error('No se pudo enviar la imagen.', 'SEND_FAILED', 500);
        }

        // Se guarda una copia propia para que la burbuja la muestre. Sin esto el chat tendria
        // un hueco donde el operador mando algo, y no habria rastro de QUE mando.
        $ruta = $this->store->guardarArchivo($listo['path'], $listo['mime'], $contact->id);

        $conversation = Conversation::create([
            'contact_id'     => $contact->id,
            'user_id'        => $request->user()?->id,
            'direction'      => 'outbound',
            'message_type'   => 'image',
            'body'           => $caption ?? '',
            'wa_message_id'  => data_get($envio, 'body.messages.0.id'),
            'status'         => 'sent',
            'window_open'    => true,
            // El id que Meta le dio al archivo al subirlo. Es el mismo concepto que en los
            // mensajes que entran (el id del archivo en Meta), y vale 30 dias: con el, un
            // reenvio del mismo archivo no tendria que volver a subirlo.
            'media_id'       => $subida['media_id'],
            'media_path'     => $ruta,
            'media_mime'     => $listo['mime'],
            'media_size'     => filesize($this->store->rutaAbsoluta($ruta)) ?: null,
            'media_filename' => $archivo->getClientOriginalName(),
            'media_status'   => 'stored',
        ]);

        event(new ConversationUpdated($contact->id));

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'id'           => $conversation->id,
                'body'         => $conversation->body,
                'direction'    => 'outbound',
                'status'       => 'sent',
                'message_type' => 'image',
                'media_status' => 'stored',
                'media_mime'   => $conversation->media_mime,
                'media_size'   => $conversation->media_size,
                'media_inline' => true,
                'created_at'   => $conversation->created_at,
            ],
        ], 201);
    }

    /**
     * La peticion venia vacia porque pesaba mas de lo que PHP acepta.
     *
     * Se mira `Content-Length` y no el archivo, porque cuando se pasa de `post_max_size` no
     * queda archivo que mirar: PHP vacia `$_POST` y `$_FILES` antes de que Laravel exista. El
     * encabezado sigue ahi: lo pone el servidor web, no PHP.
     */
    private function superaElTamanoDePeticion(Request $request): bool
    {
        $pesa = (int) $request->header('Content-Length', 0);
        $tope = $this->enBytes((string) ini_get('post_max_size'));

        return $tope > 0 && $pesa > $tope;
    }

    /** Convierte los tamanos de php.ini ("20M", "8192K", "1G") a bytes. */
    private function enBytes(string $tamano): int
    {
        $tamano = trim($tamano);

        if ($tamano === '') {
            return 0;
        }

        $numero = (int) $tamano;

        return match (strtolower(substr($tamano, -1))) {
            'g'     => $numero * 1024 * 1024 * 1024,
            'm'     => $numero * 1024 * 1024,
            'k'     => $numero * 1024,
            default => $numero,
        };
    }

    /** Borra el archivo que genero el procesador, si genero uno. */
    private function limpiar(array $listo): void
    {
        if ($listo['temporal'] && $listo['path'] && is_file($listo['path'])) {
            @unlink($listo['path']);
        }
    }

    private function error(string $message, string $code, int $status = 422): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $message,
            'code'    => $code,
        ], $status);
    }

    private function noEncontrado(): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => 'Archivo no encontrado.',
        ], 404);
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
