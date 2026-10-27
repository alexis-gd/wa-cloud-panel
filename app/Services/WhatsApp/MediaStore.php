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
     * Escribe el archivo y devuelve su ruta relativa dentro del disco.
     *
     * El `contactId` entra en la ruta para poder borrar todo lo de una persona de un golpe el
     * dia que ejerza su derecho de supresion, sin tener que recorrer la base.
     */
    public function guardar(string $contenido, ?string $mime, int $contactId): string
    {
        $ruta = sprintf(
            '%s/%s/%d/%s.%s',
            self::CARPETA,
            now()->format('Y/m'),
            $contactId,
            (string) Str::uuid(),
            $this->extension($mime),
        );

        Storage::disk(self::DISK)->put($ruta, $contenido);

        return $ruta;
    }

    /** Contenido del archivo, o null si ya no esta (borrado por retencion, disco limpiado). */
    public function leer(?string $ruta): ?string
    {
        if (! $ruta || ! Storage::disk(self::DISK)->exists($ruta)) {
            return null;
        }

        return Storage::disk(self::DISK)->get($ruta);
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
        // El mime puede venir con parametros: "audio/ogg; codecs=opus".
        $limpio = trim(explode(';', (string) $mime)[0]);

        return self::EXTENSIONES[$limpio] ?? 'bin';
    }
}
