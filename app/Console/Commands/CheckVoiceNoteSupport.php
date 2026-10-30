<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Services\WhatsApp\MediaStore;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Diagnostico de las notas de voz: que puede hacer ffmpeg en ESTE servidor, y que estamos
 * produciendo de verdad.
 *
 * Existe porque la nota llegaba a WhatsApp, se veia bien y no se reproducia - y desde el panel
 * no hay forma de saber si el problema es el servidor, la conversion o el envio. Adivinar dos
 * veces sale mas caro que mirar una.
 */
class CheckVoiceNoteSupport extends Command
{
    protected $signature = 'wa:probar-nota-de-voz
                            {--guardar= : Ruta donde dejar el ogg de prueba para escucharlo}';

    protected $description = 'Revisa si este servidor puede producir notas de voz que WhatsApp sepa reproducir';

    public function handle(MediaStore $store): int
    {
        $binario = (string) config('media.ffmpeg');

        if (trim($binario) === '') {
            $this->error('FFMPEG_PATH esta vacio en el .env: las notas de voz estan apagadas a proposito.');

            return self::FAILURE;
        }

        if (! $this->hayFfmpeg($binario)) {
            return self::FAILURE;
        }

        if (! $this->hayLibopus($binario)) {
            return self::FAILURE;
        }

        if (! $this->conviertePrueba($binario)) {
            return self::FAILURE;
        }

        $this->ultimaEnviada($binario, $store);

        return self::SUCCESS;
    }

    private function hayFfmpeg(string $binario): bool
    {
        $p = $this->correr([$binario, '-version']);

        if (! $p->isSuccessful()) {
            $this->error("No se pudo ejecutar ffmpeg ({$binario}).");
            $this->line('  Instalar con:  sudo apt install -y ffmpeg');

            return false;
        }

        $this->info('OK  ffmpeg responde: ' . strtok($p->getOutput(), "\n"));

        return true;
    }

    /**
     * Un ffmpeg sin libopus compilado falla al convertir y el panel solo dice "avisa a soporte".
     * Aqui se ve de una.
     */
    private function hayLibopus(string $binario): bool
    {
        $p = $this->correr([$binario, '-hide_banner', '-encoders']);

        if (! str_contains($p->getOutput(), 'libopus')) {
            $this->error('Este ffmpeg NO trae el codificador libopus.');
            $this->line('  WhatsApp solo reproduce notas de voz en Ogg/Opus, asi que sin esto no hay forma.');
            $this->line('  En Ubuntu el paquete `ffmpeg` normal si lo trae; revisa si es una compilacion propia.');

            return false;
        }

        $this->info('OK  libopus disponible');

        return true;
    }

    /** Genera dos segundos de tono y los pasa por EXACTAMENTE las banderas que usa el panel. */
    private function conviertePrueba(string $binario): bool
    {
        $salida = sys_get_temp_dir() . '/prueba-nota-de-voz.ogg';

        $p = $this->correr([
            $binario, '-y', '-hide_banner', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2',
            '-vn', '-c:a', 'libopus', '-b:a', '32k', '-ar', '48000', '-ac', '1', '-f', 'ogg',
            $salida,
        ]);

        if (! $p->isSuccessful() || ! is_file($salida) || filesize($salida) === 0) {
            $this->error('La conversion de prueba fallo.');
            $this->line('  ' . trim($p->getErrorOutput()));

            return false;
        }

        $this->info('OK  conversion de prueba: ' . number_format(filesize($salida)) . ' bytes');
        $this->describir($binario, $salida, '    ');

        if ($destino = $this->option('guardar')) {
            copy($salida, $destino);
            $this->line("    Copiado a {$destino} - bajalo y escuchalo para descartar el audio.");
        }

        @unlink($salida);

        return true;
    }

    /**
     * Lo ULTIMO que se le mando a un contacto de verdad.
     *
     * La prueba de arriba dice que el servidor PUEDE; esto dice que de hecho PRODUJO. No es lo
     * mismo: entre una y otra esta el archivo que subio el operador.
     */
    private function ultimaEnviada(string $binario, MediaStore $store): void
    {
        $audio = Conversation::where('direction', 'outbound')
            ->where('message_type', 'audio')
            ->whereNotNull('media_path')
            ->latest('id')
            ->first();

        if (! $audio) {
            $this->newLine();
            $this->line('Todavia no se ha mandado ninguna nota de voz desde el panel.');

            return;
        }

        $ruta = $store->rutaAbsoluta($audio->media_path);

        $this->newLine();
        $this->line("Ultima nota de voz enviada (id {$audio->id}, " . $audio->created_at->format('Y-m-d H:i') . '):');

        if (! is_file($ruta)) {
            $this->warn('  El archivo ya no esta en disco: ' . $audio->media_path);

            return;
        }

        $this->line('  ' . number_format(filesize($ruta)) . " bytes · mime guardado: {$audio->media_mime}");
        $this->describir($binario, $ruta, '  ');
        $this->newLine();
        $this->line('  Lo que debe decir: Audio: opus, 48000 Hz, mono');
    }

    /** Lo que ffmpeg ve dentro del archivo. Se lee de su salida de error, que es donde lo escribe. */
    private function describir(string $binario, string $archivo, string $sangria): void
    {
        $p = $this->correr([$binario, '-hide_banner', '-i', $archivo]);

        foreach (explode("\n", $p->getErrorOutput()) as $linea) {
            if (str_contains($linea, 'Stream #') || str_contains($linea, 'Duration')) {
                $this->line($sangria . trim($linea));
            }
        }
    }

    private function correr(array $comando): Process
    {
        $p = new Process($comando);
        $p->setTimeout(30);
        $p->run();

        return $p;
    }
}
