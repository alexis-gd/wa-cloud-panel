<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se emite cuando entra un mensaje de un contacto (respuesta de WhatsApp).
 * El panel lo escucha por WebSocket para mostrar la respuesta en vivo, sin recargar.
 *
 * Va por la cola (ShouldBroadcast, no ShouldBroadcastNow): no frena el webhook ni
 * lo rompe si el servidor WS esta caido (el job de broadcast reintenta aparte).
 */
class InboundMessageReceived implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $contactId,
        public ?string $contactName,
        public string $body,
        public string $channel,     // 'whatsapp' | 'sms'
        public string $receivedAt,  // ya formateado en hora de Mexico
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('conversations');
    }

    // Nombre corto del evento para el frontend (Echo escucha '.inbound.message').
    public function broadcastAs(): string
    {
        return 'inbound.message';
    }

    public function broadcastWith(): array
    {
        return [
            'contact_id'   => $this->contactId,
            'contact_name' => $this->contactName,
            'body'         => $this->body,
            'channel'      => $this->channel,
            'received_at'  => $this->receivedAt,
        ];
    }
}
