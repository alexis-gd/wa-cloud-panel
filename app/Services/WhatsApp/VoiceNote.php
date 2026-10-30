<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Deja la grabacion del operador en algo que WhatsApp acepte.
 *
 * **Por que hace falta convertir.** El navegador no deja elegir el formato: graba en lo que su
 * motor sabe grabar. Chrome, Edge y Firefox entregan WebM, y WhatsApp no acepta WebM. Safari
 * entrega MP4, que si acepta. O sea que sin conversion, la nota de voz funcionaria en un
 * navegador y en los otros tres fallaria al final, despues de que el operador ya grabo.
 *
 * **Por que TODA grabacion se recodifica.** La primera version cambiaba solo el envase
 * (`-c:a copy`), apostando a que el Opus de dentro del WebM servia tal cual. En produccion eso
 * entrego notas que WhatsApp ni siquiera pudo descargar. Y Meta pide tres cosas para que algo
 * se vea como nota de voz y no como archivo adjunto: **Ogg**, **codec Opus** y **mono**.
 * Copiar no garantiza ninguna de las tres - el navegador puede grabar en estereo, y Safari
 * entrega MP4, que no es Ogg. Recodificar a 32 kbps mono (lo mismo que usa WhatsApp para sus
 * propias notas) cuesta decimas de segundo y cumple las tres.
 *
 * **Por que el panel pregunta primero si hay ffmpeg.** Es una dependencia del sistema, no del
 * proyecto: en un servidor sin ella el envio fallaria DESPUES de grabar, que es el peor momento
 * para enterarse. `disponible()` deja que el panel esconda el microfono y no prometa nada.
 */
class VoiceNote
{
    /** Tope de Meta para audio. El mismo que en `OutboundAttachment`. */
    public const MAX_BYTES = 16 * 1024 * 1024;

    /** Nombre con el que queda guardada. El navegador no le pone ninguno a una grabacion. */
    public const NOMBRE = 'Nota de voz.ogg';

    /**
     * Lo que puede llegar del grabador del navegador.
     *
     * Hay dos nombres para el WebM porque el formato es el mismo para audio y para video, y
     * quien adivina el tipo por el contenido (que es como lo valida Laravel) suele decir
     * `video/webm` aunque dentro solo venga audio.
     */
    private const ENTRADA = [
        'audio/webm',
        'video/webm',
        'audio/ogg',
        'application/ogg',
        'audio/opus',
        'audio/x-opus+ogg',
        'audio/mp4',
        'video/mp4',
    ];

    /** Formatos que el panel acepta en una nota de voz, para la validacion de la peticion. */
    public static function mimesAceptados(): array
    {
        return self::ENTRADA;
    }

    /**
     * Hay ffmpeg en este servidor?
     *
     * Se guarda en cache una hora porque la respuesta no cambia entre peticiones y la pregunta
     * se hace cada vez que se abre una conversacion: levantar un proceso para eso en cada
     * apertura seria pagar un precio real por un dato fijo.
     */
    public static function disponible(): bool
    {
        return (bool) Cache::remember('media.ffmpeg', 3600, fn () => self::responde());
    }

    private static function responde(): bool
    {
        $binario = (string) config('media.ffmpeg');

        // Vacio a proposito: asi la suite de tests no levanta un proceso por cada chat que
        // abre. Un servidor de verdad siempre trae un valor (el default es 'ffmpeg').
        if (trim($binario) === '') {
            return false;
        }

        try {
            $proceso = new Process([$binario, '-version']);
            $proceso->setTimeout(10);
            $proceso->run();

            return $proceso->isSuccessful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Misma forma que devuelve `OutboundAttachment::preparar()`, para que el controlador trate
     * los dos caminos igual.
     *
     * @return array{ok: bool, path: ?string, mime: ?string, tipo: ?string, temporal: bool, error: ?string}
     */
    public function preparar(UploadedFile $archivo): array
    {
        $mime = $archivo->getMimeType();

        if (! in_array($mime, self::ENTRADA, true)) {
            return $this->falla('Esa grabación no tiene un formato que se pueda enviar.');
        }

        $pesa = $archivo->getSize();

        if ($pesa !== false && $pesa > self::MAX_BYTES) {
            return $this->falla('La nota de voz pasa del máximo que acepta WhatsApp (16 MB).');
        }

        // Sin excepciones, ni siquiera para lo que ya llega en Ogg: un Ogg estereo se entrega
        // como archivo adjunto, no como nota de voz, y el operador no tiene forma de saberlo.
        return $this->convertir($archivo->getRealPath());
    }

    /**
     * Lo que haya grabado el navegador -> Ogg/Opus mono.
     *
     * Cada bandera esta por un requisito de Meta, no por gusto:
     *
     * - `-vn`        descarta cualquier pista de video (el WebM del grabador puede traer una vacia)
     * - `-c:a libopus` el codec que Meta exige para una nota de voz
     * - `-ac 1`      mono, tambien exigido: en estereo se entrega como archivo adjunto
     * - `-ar 48000`  la frecuencia nativa de Opus; cualquier otra lo obliga a remuestrear
     * - `-b:a 32k`   lo que usa WhatsApp para sus propias notas: un minuto pesa ~240 KB
     * - `-f ogg`     obligatorio porque el temporal no tiene extension y ffmpeg no puede
     *                deducir el formato del nombre
     */
    private function convertir(string $origen): array
    {
        $salida = self::temporalOgg();

        if ($salida === null) {
            return $this->falla('No se pudo preparar la nota de voz.');
        }

        $opus = ['-i', $origen, '-vn', '-c:a', 'libopus', '-b:a', '32k', '-ar', '48000', '-ac', '1', '-f', 'ogg', $salida];

        if ($this->ffmpeg($opus) && filesize($salida) > 0) {
            return $this->listo($salida, 'audio/ogg', true);
        }

        @unlink($salida);

        return $this->falla('No se pudo convertir la nota de voz. Avisa a soporte.');
    }

    /**
     * Un temporal que SI termina en `.ogg`.
     *
     * `tempnam()` entrega un archivo sin extension, y ese nombre es el que viaja a Meta al
     * subirlo (`basename($ruta)`). La guia de Meta pide que la extension concuerde con el tipo
     * declarado; sin ella, WhatsApp acepta la subida, dibuja la burbuja de nota de voz y
     * despues no puede reproducirla: "algo fallo con el archivo de audio".
     *
     * Las imagenes y los documentos no lo necesitan - se mandan desde el temporal de PHP, que
     * tampoco tiene extension, y llegan bien. El audio es mas estricto.
     */
    private static function temporalOgg(): ?string
    {
        $base = tempnam(sys_get_temp_dir(), 'wa-voz-');

        if ($base === false) {
            return null;
        }

        $conExtension = $base . '.ogg';

        // `tempnam` ya creo el archivo vacio; se renombra para quedarse con uno solo.
        if (! @rename($base, $conExtension)) {
            @unlink($base);

            return null;
        }

        return $conExtension;
    }

    private function ffmpeg(array $argumentos): bool
    {
        try {
            $proceso = new Process(array_merge(
                [config('media.ffmpeg'), '-y', '-hide_banner', '-loglevel', 'error'],
                $argumentos,
            ));

            $proceso->setTimeout(60);
            $proceso->run();

            if (! $proceso->isSuccessful()) {
                Log::warning('ffmpeg no pudo convertir la nota de voz', [
                    'error' => trim($proceso->getErrorOutput()),
                ]);
            }

            return $proceso->isSuccessful();
        } catch (\Throwable $e) {
            Log::error('ffmpeg falló al ejecutarse', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function listo(string $path, string $mime, bool $temporal): array
    {
        return ['ok' => true, 'path' => $path, 'mime' => $mime, 'tipo' => 'audio', 'temporal' => $temporal, 'error' => null];
    }

    private function falla(string $error): array
    {
        return ['ok' => false, 'path' => null, 'mime' => null, 'tipo' => null, 'temporal' => false, 'error' => $error];
    }
}
