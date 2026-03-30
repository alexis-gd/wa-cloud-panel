<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_requiere_autenticacion(): void
    {
        $this->getJson('/api/dashboard/stats')->assertStatus(401);
    }

    public function test_stats_devuelve_estructura_correcta(): void
    {
        $this->actingAsOperator()
             ->getJson('/api/dashboard/stats')
             ->assertStatus(200)
             ->assertJsonStructure([
                 'status',
                 'data' => [
                     'stats'    => ['sent', 'delivered', 'read', 'failed'],
                     'contacts' => ['total', 'active', 'opted_out', 'invalid'],
                     'recent_messages',
                 ],
             ])
             ->assertJsonPath('status', 'ok');
    }

    public function test_stats_cuenta_contactos_correctamente(): void
    {
        Contact::factory()->count(3)->create(['status' => 'active']);
        Contact::factory()->count(2)->create(['status' => 'opted_out']);
        Contact::factory()->count(1)->create(['status' => 'invalid']);

        $this->actingAsOperator()
             ->getJson('/api/dashboard/stats')
             ->assertJsonPath('data.contacts.total', 6)
             ->assertJsonPath('data.contacts.active', 3)
             ->assertJsonPath('data.contacts.opted_out', 2)
             ->assertJsonPath('data.contacts.invalid', 1);
    }

    public function test_stats_cuenta_mensajes_por_status(): void
    {
        $phone = PhoneNumber::factory()->create();

        MessageLog::factory()->count(4)->create(['phone_number_id' => $phone->id, 'status' => 'sent',      'sent_at' => now()]);
        MessageLog::factory()->count(2)->create(['phone_number_id' => $phone->id, 'status' => 'delivered', 'sent_at' => now()]);
        MessageLog::factory()->count(1)->create(['phone_number_id' => $phone->id, 'status' => 'failed',    'sent_at' => now()]);

        $this->actingAsOperator()
             ->getJson('/api/dashboard/stats')
             ->assertJsonPath('data.stats.sent', 4)
             ->assertJsonPath('data.stats.delivered', 2)
             ->assertJsonPath('data.stats.failed', 1)
             ->assertJsonPath('data.stats.read', 0);
    }

    public function test_stats_devuelve_ultimos_20_mensajes(): void
    {
        $phone = PhoneNumber::factory()->create();
        MessageLog::factory()->count(25)->create(['phone_number_id' => $phone->id, 'sent_at' => now()]);

        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/stats')
                    ->assertStatus(200);

        $this->assertCount(20, $res->json('data.recent_messages'));
    }
}
