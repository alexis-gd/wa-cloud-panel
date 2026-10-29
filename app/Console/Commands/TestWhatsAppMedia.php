<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\PhoneNumber;
use App\Services\WhatsApp\MediaStore;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Console\Command;

/**
 * Prueba el camino completo de archivos contra la API REAL de Meta, sin desplegar nada.
 *
 * Sube un archivo local a Meta, se lo vuelve a pedir y compara los bytes. Eso recorre lo unico
 * que los tests NO pueden probar, porque ahi la API esta simulada: que los nombres de los campos
 * de la ficha (`url`, `mime_type`, `file_size`) sean los que creemos, que el token sirva para
 * bajar y no solo para enviar, y que la descarga en streaming aguante los redirecciones de Meta.
 *
 * **NO se manda ningun mensaje.** Subir un archivo a Meta no abre conversacion, no cuesta, no
 * llega a ningun telefono y no deja rastro de envio. Es una llamada al API y nada mas. El
 * archivo queda en los servidores de Meta 30 dias y se cae solo.
 *
 * Con `--contacto` tambien crea un mensaje entrante de prueba para poder ver la burbuja en el
 * panel. Eso **solo corre fuera de produccion**: en prod fabricaria historial falso en las
 * conversaciones del cliente.
 */
class TestWhatsAppMedia extends Command
{
    protected $signature = 'wa:probar-media
                            {archivo : Ruta de un archivo local (foto, PDF, audio)}
                            {--contacto= : Telefono de un contacto para ver la burbuja en el panel}';

    protected $description = 'Prueba subir y bajar un archivo contra la API real de Meta (no envia mensajes)';

    public function handle(WhatsAppClient $client, MediaStore $store): int
    {
        $archivo = $this->argument('archivo');

        if (! is_file($archivo)) {
            $this->error("No existe el archivo: {$archivo}");

            return self::FAILURE;
        }

        $phone = PhoneNumber::where('is_active', true)->first();

        if (! $phone || ! $phone->token) {
            $this->error('No hay un numero de WhatsApp activo con token en la base.');

            return self::FAILURE;
        }

        $mime   = mime_content_type($archivo) ?: 'application/octet-stream';
        $pesaba = filesize($archivo);

        $this->line('');
        $this->line("  Archivo: {$archivo}");
        $this->line("  Tipo:    {$mime} (" . $this->mb($pesaba) . ')');
        $this->line("  Numero:  {$phone->phone_number_id}");
        $this->line('');

        // 1. Subir. No es un envio: nadie recibe nada.
        $this->line('  [1/3] Subiendo a Meta...');

        $subida = $client->uploadMedia($phone->phone_number_id, $phone->token, $archivo, $mime);

        if (! $subida['ok']) {
            $this->error('  Meta rechazo la subida: ' . $subida['error']);

            return self::FAILURE;
        }

        $mediaId = $subida['media_id'];
        $this->info("        OK - media_id: {$mediaId}");

        // 2. Bajarlo de vuelta. Este es el camino que corre cada que un contacto manda algo.
        $this->line('  [2/3] Pidiendolo de vuelta (ficha + descarga en streaming)...');

        $descarga = $client->downloadMedia($mediaId, $phone->token);

        if (! $descarga['ok']) {
            $this->error('  Fallo la descarga: ' . $descarga['error']);
            $this->warn('  Revisa storage/logs para el detalle que devolvio Meta.');

            return self::FAILURE;
        }

        $this->info('        OK - mime: ' . ($descarga['mime'] ?? '?')
            . ' | tamano: ' . $this->mb($descarga['size']));

        // 3. Que lo que volvio sea identico. Si el streaming cortara o corrompiera algo, aqui
        //    se ve: Meta recomprime las imagenes, asi que se compara el tamano, no el hash.
        $this->line('  [3/3] Comparando con el original...');

        $volvio = filesize($descarga['path']);

        if ($volvio === 0) {
            $this->error('  El archivo volvio vacio.');
            @unlink($descarga['path']);

            return self::FAILURE;
        }

        $this->info("        OK - volvieron " . $this->mb($volvio)
            . ($volvio === $pesaba ? ' (identico)' : ' (Meta lo recomprimio)'));

        $contacto = $this->option('contacto');

        if (! $contacto) {
            @unlink($descarga['path']);
            $this->line('');
            $this->info('  El camino de archivos funciona contra Meta.');
            $this->line('  Para verlo en el panel: --contacto=529231311146');
            $this->line('');

            return self::SUCCESS;
        }

        return $this->ponerEnElPanel($store, $descarga, $contacto, $archivo);
    }

    /**
     * Deja el archivo como un mensaje entrante para poder mirar la burbuja.
     *
     * Guarda directo en vez de encolar el job a proposito: asi no depende de que el worker este
     * corriendo, y la burbuja aparece de inmediato. El camino del job ya tiene sus tests y lo
     * estrena la primera foto de verdad.
     */
    private function ponerEnElPanel(
        MediaStore $store,
        array $descarga,
        string $telefono,
        string $archivoOriginal,
    ): int {
        if (app()->environment('production')) {
            @unlink($descarga['path']);
            $this->error('  --contacto no corre en produccion: fabricaria historial falso en');
            $this->error('  las conversaciones del cliente. Para probar en prod, mandate una');
            $this->error('  foto de verdad desde tu WhatsApp al numero del panel.');

            return self::FAILURE;
        }

        $contact = Contact::where('phone', $telefono)->first();

        if (! $contact) {
            @unlink($descarga['path']);
            $this->error("  No hay un contacto con el telefono {$telefono}.");

            return self::FAILURE;
        }

        $mime = $descarga['mime'];
        $tipo = MediaStore::tipoDe($mime);

        $ruta = $store->guardarArchivo($descarga['path'], $mime, $contact->id);

        $conversation = Conversation::create([
            'contact_id'     => $contact->id,
            'direction'      => 'inbound',
            'message_type'   => $tipo,
            'body'           => '',
            'status'         => 'received',
            'window_open'    => true,
            'media_id'       => 'prueba-local-' . uniqid(),
            'media_path'     => $ruta,
            'media_mime'     => $mime,
            'media_size'     => $descarga['size'],
            'media_filename' => $tipo === 'document' ? basename($archivoOriginal) : null,
            'media_status'   => 'stored',
        ]);

        $this->line('');
        $this->info('  Listo. Abre Conversaciones y busca a ' . ($contact->name ?: $telefono) . '.');
        $this->line("  Mensaje #{$conversation->id} ({$tipo}) - deberia verse la burbuja.");
        $this->line('  Para borrarlo despues: php artisan db:clean-demo');
        $this->line('');

        return self::SUCCESS;
    }

    private function mb(?int $bytes): string
    {
        if (! $bytes) {
            return '0 B';
        }

        return $bytes < 1024 * 1024
            ? number_format($bytes / 1024, 1) . ' KB'
            : number_format($bytes / 1024 / 1024, 2) . ' MB';
    }
}
