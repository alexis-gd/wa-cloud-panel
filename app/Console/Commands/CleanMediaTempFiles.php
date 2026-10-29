<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Borra los archivos a medias que deja una descarga interrumpida.
 *
 * Desde que los archivos de WhatsApp bajan en streaming, la descarga cae primero en un `.part`
 * bajo `storage/app/tmp/media` y de ahi se MUEVE a su lugar. Los dos finales previsibles ya
 * estan cubiertos en codigo: si la descarga falla, `WhatsAppClient` borra el temporal; si falla
 * al moverlo, lo borra el job.
 *
 * Lo que ningun `try/catch` alcanza es que al worker lo maten en seco - un `kill -9`, que se
 * acabe la memoria, un reinicio del servidor. Ahi el proceso desaparece sin ejecutar nada mas y
 * el `.part` se queda ocupando disco para siempre, sin nombre por el que nadie lo vaya a buscar.
 * Con documentos de hasta 100 MB, unos cuantos de esos se notan.
 */
class CleanMediaTempFiles extends Command
{
    protected $signature = 'media:limpiar-temporales {--horas=24 : Antiguedad minima para borrar}';

    protected $description = 'Borra descargas de archivos interrumpidas (storage/app/tmp/media)';

    public function handle(): int
    {
        $horas = max(1, (int) $this->option('horas'));
        $corte = now()->subHours($horas)->getTimestamp();

        $borrados = 0;
        $bytes    = 0;

        foreach (glob(storage_path('app/tmp/media/*.part')) ?: [] as $archivo) {
            // El corte es por antiguedad y no "borra todo": una descarga en curso tiene su
            // `.part` abierto en este momento. El tope de la descarga son 120 segundos, asi
            // que cualquier cosa de horas atras es basura con seguridad.
            if (filemtime($archivo) > $corte) {
                continue;
            }

            $peso = filesize($archivo) ?: 0;

            if (@unlink($archivo)) {
                $borrados++;
                $bytes += $peso;
            }
        }

        if ($borrados === 0) {
            $this->info('Sin descargas interrumpidas que borrar.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Borradas %d descargas interrumpidas (%s MB liberados).',
            $borrados,
            number_format($bytes / 1024 / 1024, 1),
        ));

        return self::SUCCESS;
    }
}
