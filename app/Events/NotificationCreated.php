<?php

namespace App\Events;

use App\Models\AppNotification;
use Carbon\Carbon;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se emite cuando se crea una AppNotification (envio fallido, webhook caido,
 * numero pausado, etc.). El panel lo escucha por WebSocket para prender la
 * campanita al instante, sin polling.
 *
 * Va por la cola (ShouldBroadcast): no frena la request que creo la notificacion
 * ni la rompe si el servidor WS esta caido.
 */
class NotificationCreated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $notificationId,
        public string $type,
        public string $title,
        public ?string $body,
        public ?string $createdAt,  // ya formateado en hora de Mexico
    ) {}

    public static function fromModel(AppNotification $n): self
    {
        return new self(
            $n->id,
            $n->type,
            $n->title,
            $n->body,
            $n->created_at
                ? Carbon::parse($n->getRawOriginal('created_at'), 'UTC')
                    ->setTimezone('America/Mexico_City')
                    ->format('Y-m-d H:i')
                : null,
        );
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('notifications');
    }

    // Nombre corto del evento para el frontend (Echo escucha '.notification.created').
    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id'         => $this->notificationId,
            'type'       => $this->type,
            'title'      => $this->title,
            'body'       => $this->body,
            'read'       => false,
            'created_at' => $this->createdAt,
        ];
    }
}
