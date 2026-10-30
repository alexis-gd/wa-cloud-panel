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
 * **Por que se cambia el envase y no el contenido.** Un WebM de grabacion lleva Opus adentro,
 * que es exactamente lo que lleva un Ogg de WhatsApp. Cambiar el envase (`-c:a copy`) es mover
 * bytes de un contenedor a otro: tarda milisegundos y no pierde nada. Recodificar tardaria
 * segundos de CPU por nota y sonaria peor. Solo si el copiado falla se recodifica, que es el
 * cinturon para un navegador que grabe en algo que no sea Opus.
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

    /** Lo que ya viene listo: se manda tal cual, sin pasar por ffmpeg. */
    private const SIN_CONVERTIR = [
        'audio/ogg'        => 'audio/ogg',
        'application/ogg'  => 'audio/ogg',
        'audio/opus'       => 'audio/ogg',
        'audio/x-opus+ogg' => 'audio/ogg',
        'audio/mp4'        => 'audio/mp4',
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

        if (isset(self::SIN_CONVERTIR[$mime])) {
            return $this->listo($archivo->getRealPath(), self::SIN_CONVERTIR[$mime], false);
        }

        return $this->convertir($archivo->getRealPath());
    }

    /**
     * WebM a Ogg.
     *
     * Primero copiando el audio tal cual. Si el copiado no sale (el navegador grabo en algo que
     * no es Opus), se recodifica: 32 kbps mono es lo que usa WhatsApp para sus propias notas de
     * voz, suena igual y deja una nota de un minuto en unos 240 KB.
     */
    private function convertir(string $origen): array
    {
        $salida = tempnam(sys_get_temp_dir(), 'wa-voz-');

        if ($salida === false) {
            return $this->falla('No se pudo preparar la nota de voz.');
        }

        // `-f ogg` es obligatorio: el temporal no tiene extension, asi que ffmpeg no puede
        // deducir el formato del nombre.
        $copiar = ['-i', $origen, '-vn', '-c:a', 'copy', '-f', 'ogg', $salida];

        if ($this->ffmpeg($copiar) && filesize($salida) > 0) {
            return $this->listo($salida, 'audio/ogg', true);
        }

        $recodificar = ['-i', $origen, '-vn', '-c:a', 'libopus', '-b:a', '32k', '-ar', '48000', '-ac', '1', '-f', 'ogg', $salida];

        if ($this->ffmpeg($recodificar) && filesize($salida) > 0) {
            return $this->listo($salida, 'audio/ogg', true);
        }

        @unlink($salida);

        return $this->falla('No se pudo convertir la nota de voz. Avisa a soporte.');
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
