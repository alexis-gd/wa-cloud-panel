<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_devuelve_notificaciones_con_unread_count(): void
    {
        AppNotification::create(['type' => 'delivery_failed', 'title' => 'Entrega fallida', 'body' => 'Mensaje 1']);
        AppNotification::create(['type' => 'delivery_failed', 'title' => 'Entrega fallida', 'body' => 'Mensaje 2', 'read_at' => now()]);

        $this->actingAsAgent()
            ->getJson('/api/notifications')
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonCount(2, 'data.notifications');
    }

    public function test_index_sin_notificaciones_devuelve_lista_vacia(): void
    {
        $this->actingAsOperator()
            ->getJson('/api/notifications')
            ->assertStatus(200)
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('data.notifications', []);
    }

    public function test_index_sin_autenticacion_devuelve_401(): void
    {
        $this->getJson('/api/notifications')->assertStatus(401);
    }

    public function test_mark_read_all_actualiza_read_at(): void
    {
        AppNotification::create(['type' => 'delivery_failed', 'title' => 'T1', 'body' => 'B1']);
        AppNotification::create(['type' => 'delivery_failed', 'title' => 'T2', 'body' => 'B2']);

        $this->actingAsAdmin()
            ->postJson('/api/notifications/read-all')
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok');

        $this->assertEquals(0, AppNotification::whereNull('read_at')->count());
    }

    public function test_mark_read_all_sin_autenticacion_devuelve_401(): void
    {
        $this->postJson('/api/notifications/read-all')->assertStatus(401);
    }

    public function test_destroy_elimina_notificacion(): void
    {
        $n = AppNotification::create(['type' => 'delivery_failed', 'title' => 'T1', 'body' => 'B1']);

        $this->actingAsAdmin()
            ->deleteJson("/api/notifications/{$n->id}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok');

        $this->assertDatabaseMissing('app_notifications', ['id' => $n->id]);
    }

    public function test_destroy_sin_autenticacion_devuelve_401(): void
    {
        $n = AppNotification::create(['type' => 'delivery_failed', 'title' => 'T', 'body' => 'B']);
        $this->deleteJson("/api/notifications/{$n->id}")->assertStatus(401);
    }

    public function test_index_created_at_en_timezone_mexico(): void
    {
        AppNotification::create(['type' => 'delivery_failed', 'title' => 'T', 'body' => 'B']);

        $response = $this->actingAsOperator()
            ->getJson('/api/notifications')
            ->assertStatus(200);

        $createdAt = $response->json('data.notifications.0.created_at');
        $this->assertNotNull($createdAt);
        // Formato Y-m-d H:i — verifica que sea una fecha válida, no null ni UTC raw
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $createdAt);
    }

    public function test_index_devuelve_maximo_20_notificaciones(): void
    {
        for ($i = 0; $i < 25; $i++) {
            AppNotification::create(['type' => 'delivery_failed', 'title' => "T{$i}", 'body' => "B{$i}"]);
        }

        $response = $this->actingAsOperator()
            ->getJson('/api/notifications')
            ->assertStatus(200);

        $this->assertCount(20, $response->json('data.notifications'));
    }
}
