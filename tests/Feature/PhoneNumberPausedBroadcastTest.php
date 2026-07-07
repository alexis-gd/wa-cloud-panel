<?php

namespace Tests\Feature;

use App\Events\PhoneNumberPaused;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tiempo real del semaforo del Dashboard: cuando el circuit breaker pausa un numero
 * (pauseFor), se emite PhoneNumberPaused; el Dashboard lo escucha por WebSocket y
 * prende el semaforo "PAUSADO" al instante (sin polling).
 */
class PhoneNumberPausedBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_pausar_numero_emite_evento(): void
    {
        Event::fake([PhoneNumberPaused::class]);

        $phone = PhoneNumber::factory()->create(['paused_until' => null]);
        $phone->pauseFor(60);

        Event::assertDispatched(
            PhoneNumberPaused::class,
            fn ($e) => $e->phoneNumberId === $phone->id && $e->pausedUntil !== null,
        );
    }

    public function test_evento_va_al_canal_privado_dashboard_con_payload(): void
    {
        $phone = PhoneNumber::factory()->create(['paused_until' => null]);
        $phone->pauseFor(60);

        $event = PhoneNumberPaused::fromModel($phone);

        $this->assertSame('private-dashboard', $event->broadcastOn()->name);
        $this->assertSame('phone.paused', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame($phone->id, $payload['phone_number_id']);
        $this->assertNotNull($payload['paused_until']);
    }
}
