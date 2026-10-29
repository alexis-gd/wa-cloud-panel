<?php

namespace App\Jobs;

use App\Events\ConversationUpdated;
use App\Models\Conversation;
use App\Models\PhoneNumber;
use App\Services\WhatsApp\MediaStore;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Baja a disco el archivo que mando un contacto.
 *
 * Va en cola y no dentro del webhook por dos razones. Meta corta la conexion si el webhook
 * tarda, y reintenta el aviso completo si no le respondemos rapido: bajar un video de 16 MB ahi
 * adentro provocaria mensajes duplicados. Y si la descarga falla, en el webhook no hay forma de
 * reintentarla sin volver a procesar todo el aviso.
 *
 * El `media_id` de Meta vive 30 dias, asi que cada reintento vuelve a pedir una URL fresca. Lo
 * que caduca en minutos es la URL, no el id - por eso reintentar sirve de algo.
 */
class DownloadWhatsAppMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Espera creciente entre intentos: un tropiezo de red se resuelve en el primer reintento. */
    public array $backoff = [10, 60];

    public function __construct(private readonly int $conversationId)
    {
    }

    public function handle(WhatsAppClient $client, MediaStore $store): void
    {
        $conversation = Conversation::find($this->conversationId);

        if (! $conversation || ! $conversation->media_id) {
            return;
        }

        // Ya esta en disco: el job corrio dos veces (reintento de Meta, o el reconcile).
        // Volver a bajarlo solo gastaria ancho de banda y dejaria un archivo huerfano.
        if ($conversation->media_status === 'stored') {
            return;
        }

        $phoneNumber = PhoneNumber::where('is_active', true)->first();

        if (! $phoneNumber) {
            Log::error('DownloadWhatsAppMedia: no hay numero activo para pedir el archivo', [
                'conversation_id' => $this->conversationId,
            ]);
            $this->marcarFallida($conversation);
            return;
        }

        // El archivo llega en streaming a un temporal: `path`, no el contenido. Nunca se arma
        // completo en memoria (ver `WhatsAppClient::downloadMedia`).
        $descarga = $client->downloadMedia($conversation->media_id, $phoneNumber->token);

        if (! $descarga['ok']) {
            // `fail()` en el ultimo intento, no antes: dejar que la cola reintente es justo lo
            // que recupera una URL caducada o una caida momentanea de Meta.
            if ($this->attempts() >= $this->tries) {
                $this->marcarFallida($conversation);
            }

            throw new \RuntimeException($descarga['error'] ?? 'Descarga de archivo fallida');
        }

        $temporal = $descarga['path'];

        try {
            $ruta = $store->guardarArchivo(
                rutaOrigen: $temporal,
                mime: $descarga['mime'] ?? $conversation->media_mime,
                contactId: $conversation->contact_id,
            );
        } catch (\Throwable $e) {
            // Sin esto el temporal se queda ocupando disco para siempre: la descarga ya paso,
            // nadie mas lo va a reclamar y no tiene un nombre por el que buscarlo.
            if (is_file($temporal)) {
                @unlink($temporal);
            }

            throw $e;
        }

        $conversation->update([
            'media_path'   => $ruta,
            'media_mime'   => $descarga['mime'] ?? $conversation->media_mime,
            'media_size'   => $descarga['size'],
            'media_status' => 'stored',
        ]);

        // El panel esta esperando: la burbuja decia "descargando" hasta este momento.
        event(new ConversationUpdated($conversation->contact_id));
    }

    /**
     * La cola llama a esto cuando se agotan los intentos y la excepcion sale del `handle`.
     * Sin el, una descarga muerta se quedaria para siempre en "pending" y el operador no
     * tendria forma de saber que ese archivo ya no va a llegar.
     */
    public function failed(\Throwable $e): void
    {
        $conversation = Conversation::find($this->conversationId);

        if ($conversation) {
            $this->marcarFallida($conversation);
        }
    }

    private function marcarFallida(Conversation $conversation): void
    {
        $conversation->update(['media_status' => 'failed']);
        event(new ConversationUpdated($conversation->contact_id));
    }
}
