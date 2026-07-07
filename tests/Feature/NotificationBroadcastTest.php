<?php

namespace Tests\Feature;

use App\Events\NotificationCreated;
use App\Models\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tiempo real de la campanita: al crear una AppNotification se emite
 * NotificationCreated, el panel lo escucha por WebSocket y prende la campanita
 * al instante (sin polling).
 */
class NotificationBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_crear_notificacion_emite_evento(): void
    {
        Event::fake([NotificationCreated::class]);

        $notif = AppNotification::create([
            'type'  => 'delivery_failed',
            'title' => 'Envio fallido',
            'body'  => 'No se pudo entregar un mensaje.',
        ]);

        Event::assertDispatched(
            NotificationCreated::class,
            fn ($e) => $e->notificationId === $notif->id
                && $e->type === 'delivery_failed'
                && $e->title === 'Envio fallido',
        );
    }

    public function test_evento_va_al_canal_privado_notifications_con_payload(): void
    {
        $notif = AppNotification::create([
            'type'  => 'phone_paused',
            'title' => 'Numero pausado',
            'body'  => 'Se pauso por rate limit.',
        ]);

        $event = NotificationCreated::fromModel($notif);

        $this->assertSame('private-notifications', $event->broadcastOn()->name);
        $this->assertSame('notification.created', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame($notif->id, $payload['id']);
        $this->assertSame('phone_paused', $payload['type']);
        $this->assertSame('Numero pausado', $payload['title']);
        $this->assertFalse($payload['read']);
        $this->assertNotNull($payload['created_at']);
    }
}
