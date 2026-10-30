<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda y borra los archivos de las conversaciones.
 *
 * Existe aparte del job porque el mismo guardado lo usan dos caminos: lo que llega del contacto
 * y lo que sube el operador. Y porque **donde** se guarda no es un detalle: por aqui pasan
 * fotos de INE y comprobantes de domicilio, que son datos personales sensibles bajo la LFPDPPP.
 *
 * Tres decisiones que no hay que deshacer:
 *
 * 1. Disco `local` (storage/app), nunca `public`. Un archivo en `public/` es alcanzable por
 *    cualquiera que adivine la URL, sin pasar por el login. La INE de un cliente no puede vivir
 *    ahi. Se sirve por una ruta autenticada que ademas respeta los permisos del agente.
 *
 * 2. Nombre inventado por nosotros, no el que venga de afuera. Un contacto puede mandar un
 *    archivo llamado `../../.env` y el nombre original acaba en una ruta. El nombre real se
 *    guarda aparte, en la columna `media_filename`, solo para mostrarlo.
 *
 * 3. Carpetas por ano y mes. Con 200,000 contactos al mes, un solo directorio con cientos de
 *    miles de archivos vuelve lento hasta un `ls`.
 */
class MediaStore
{
    /** Disco privado. No cambiar a `public` ni "temporalmente para probar". */
    public const DISK = 'local';

    private const CARPETA = 'media';

    /**
     * Extension segun el tipo de archivo que reporta Meta.
     *
     * Se decide por el mime y no por el nombre: el nombre lo pone quien manda el archivo y
     * puede mentir. El mime lo reporta Meta despues de inspeccionarlo.
     */
    private const EXTENSIONES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
        'audio/ogg'       => 'ogg',
        'audio/mpeg'      => 'mp3',
        'audio/mp4'       => 'm4a',
        'audio/amr'       => 'amr',
        'video/mp4'       => 'mp4',
        'video/3gpp'      => '3gp',
    ];

    /**
     * Mete un archivo que ya esta en disco, sin pasarlo por la memoria.
     *
     * Es la UNICA forma de guardar que ofrece esta clase, a proposito. Hubo una que recibia el
     * contenido en un string y se quito: era una trampa - usarla volveria a cargar el archivo
     * completo a memoria y anularia el streaming sin que nada se vea roto. Si algo nuevo
     * necesita guardar, primero deja el archivo en disco y lo pasa por aqui.
     *
     * El archivo llega en streaming a un temporal y de ahi solo se MUEVE a su lugar. Leerlo
     * para volverlo a escribir haria pasar un documento de 100 MB completo por la memoria del
     * worker.
     *
     * El `contactId` entra en la ruta para poder borrar todo lo de una persona de un golpe el
     * dia que ejerza su derecho de supresion, sin tener que recorrer la base.
     *
     * El temporal vive bajo `storage/app`, el mismo sistema de archivos que el destino, asi que
     * mover es instantaneo (solo cambia el nombre, no copia bytes).
     *
     * @param  string  $rutaOrigen  Ruta absoluta del archivo ya descargado. Se consume: si todo
     *                              sale bien, deja de existir en su lugar anterior.
     */
    public function guardarArchivo(string $rutaOrigen, ?string $mime, int $contactId): string
    {
        $ruta = $this->rutaPara($mime, $contactId);

        $destino = Storage::disk(self::DISK)->path($ruta);
        $carpeta = dirname($destino);

        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0775, true);
        }

        // `rename` y no `copy`: copiar duplicaria el archivo en disco un instante y en un
        // documento grande eso es I/O que no hace falta.
        if (! @rename($rutaOrigen, $destino)) {
            // Entre dos sistemas de archivos distintos `rename` falla. El respaldo copia por
            // pedazos (`copy` de PHP no carga el archivo a memoria) y borra el origen.
            if (! @copy($rutaOrigen, $destino)) {
                throw new \RuntimeException("No se pudo guardar el archivo en {$ruta}");
            }

            @unlink($rutaOrigen);
        }

        // Los temporales de PHP y de `tempnam()` nacen en 0600, y `rename` conserva el modo:
        // el archivo quedaba legible SOLO para www-data. El panel funcionaba igual (lo sirve
        // PHP), pero nadie mas podia abrirlo - ni un respaldo, ni ffmpeg al diagnosticar.
        // Costo una conclusion equivocada: un audio ilegible parecia un audio corrupto.
        // 0644 y no 0664: en `storage/app`, fuera del alcance de la web, y quien entra al
        // servidor por SSH ya es el administrador.
        @chmod($destino, 0644);

        return $ruta;
    }

    /** Donde va a vivir un archivo nuevo. */
    private function rutaPara(?string $mime, int $contactId): string
    {
        return sprintf(
            '%s/%s/%d/%s.%s',
            self::CARPETA,
            now()->format('Y/m'),
            $contactId,
            (string) Str::uuid(),
            $this->extension($mime),
        );
    }

    /** Ruta absoluta en el sistema de archivos. La necesita la subida a Meta y el streaming. */
    public function rutaAbsoluta(?string $ruta): ?string
    {
        if (! $ruta || ! Storage::disk(self::DISK)->exists($ruta)) {
            return null;
        }

        return Storage::disk(self::DISK)->path($ruta);
    }

    public function borrar(?string $ruta): void
    {
        if ($ruta && Storage::disk(self::DISK)->exists($ruta)) {
            Storage::disk(self::DISK)->delete($ruta);
        }
    }

    /**
     * Tipo de mensaje que le corresponde a un archivo, para `conversations.message_type`.
     * Un mime desconocido entra como documento: se puede descargar aunque no se previsualice.
     */
    public static function tipoDe(?string $mime): string
    {
        return match (true) {
            str_starts_with((string) $mime, 'image/') => 'image',
            str_starts_with((string) $mime, 'audio/') => 'audio',
            str_starts_with((string) $mime, 'video/') => 'video',
            default                                   => 'document',
        };
    }

    private function extension(?string $mime): string
    {
        return self::extensionDe($mime) ?? 'bin';
    }

    /**
     * Tipos que es seguro MOSTRAR, en vez de obligar a descargarlos.
     *
     * Todo lo que no este aqui se descarga. El caso que esto ataja: un contacto manda un SVG
     * o un HTML como documento. Esos formatos llevan JavaScript adentro, y mostrarlos lo
     * ejecutaria en nuestro dominio, donde vive el token del operador.
     *
     * El PDF se queda porque el operador tiene que poder leer un comprobante sin bajarlo, y
     * el visor de PDF del navegador corre aislado.
     */
    private const EN_LINEA = [
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

    /**
     * Se puede mostrar este archivo, o hay que obligar a descargarlo.
     *
     * Vive aqui y no en el controlador porque la respuesta la necesitan DOS lugares: la ruta
     * que entrega el archivo y el detalle del chat, que es donde el panel decide si el boton
     * dice "Abrir" o "Descargar". Con la regla en un solo sitio no pueden contradecirse.
     */
    public static function seMuestraEnLinea(?string $mime): bool
    {
        $limpio = trim(explode(';', (string) $mime)[0]);

        return in_array($limpio, self::EN_LINEA, true);
    }

    /**
     * Extension que le corresponde a un mime, o null si no la conocemos.
     *
     * Publica porque la necesita tambien el nombre con el que el operador GUARDA el archivo,
     * no solo el nombre en disco. Un archivo sin extension deja que el navegador la adivine
     * del mime, y en Windows `image/jpeg` cae en `.jfif` por una asociacion del registro:
     * el operador termina con un archivo que varios programas no abren.
     */
    public static function extensionDe(?string $mime): ?string
    {
        // El mime puede venir con parametros: "audio/ogg; codecs=opus".
        $limpio = trim(explode(';', (string) $mime)[0]);

        return self::EXTENSIONES[$limpio] ?? null;
    }
}
