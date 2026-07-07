<?php

namespace App\Events;

use App\Models\PhoneNumber;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Se emite cuando el circuit breaker pausa un numero (rate limit 131048, bloqueo 368).
 * El Dashboard lo escucha por WebSocket para prender el semaforo "PAUSADO" al instante,
 * sin polling: es la senal de "para campanias".
 *
 * Va por la cola (ShouldBroadcast): no frena el job que pauso el numero ni lo rompe
 * si el servidor WS esta caido.
 */
class PhoneNumberPaused implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $phoneNumberId,
        public ?string $pausedUntil,  // ya formateado en hora de Mexico
    ) {}

    public static function fromModel(PhoneNumber $phone): self
    {
        return new self(
            $phone->id,
            $phone->paused_until
                ? $phone->paused_until->setTimezone('America/Mexico_City')->format('Y-m-d H:i')
                : null,
        );
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('dashboard');
    }

    // Nombre corto del evento para el frontend (Echo escucha '.phone.paused').
    public function broadcastAs(): string
    {
        return 'phone.paused';
    }

    public function broadcastWith(): array
    {
        return [
            'phone_number_id' => $this->phoneNumberId,
            'paused_until'    => $this->pausedUntil,
        ];
    }
}
