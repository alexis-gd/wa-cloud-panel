<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se emite cuando cambia algo de una conversación que el panel debe ver en vivo,
 * SIN recargar: asignación (asignar/tomar), estado (ventana 24h, snooze, baja) y
 * estado de entrega de los mensajes salientes (enviado -> entregado -> leído).
 *
 * Lleva solo el contact_id: el panel refetch la fila de la lista y, si es la
 * conversación abierta, el chat (patrón dirigido, no polling). El texto entrante
 * de un contacto viaja aparte en InboundMessageReceived (para el toast).
 *
 * Va por la cola (ShouldBroadcast): no frena la request ni la rompe si Soketi cae.
 */
class ConversationUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $contactId,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('conversations');
    }

    // Nombre corto del evento para el frontend (Echo escucha '.conversation.updated').
    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    public function broadcastWith(): array
    {
        return ['contact_id' => $this->contactId];
    }
}
