<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\WaTemplate;
use App\Services\PhoneNumberSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PhoneNumberSelectorTest extends TestCase
{
    use RefreshDatabase;

    // ── PhoneNumberSelector::available() ────────────────────────────────────

    public function test_selector_devuelve_numeros_activos_no_pausados(): void
    {
        $active  = PhoneNumber::factory()->create(['is_active' => true,  'paused_until' => null]);
        $paused  = PhoneNumber::factory()->create(['is_active' => true,  'paused_until' => now()->addHour()]);
        $inactivo = PhoneNumber::factory()->create(['is_active' => false, 'paused_until' => null]);

        $result = app(PhoneNumberSelector::class)->available();

        $this->assertCount(1, $result);
        $this->assertEquals($active->id, $result->first()->id);
    }

    public function test_selector_incluye_numero_con_pausa_expirada(): void
    {
        PhoneNumber::factory()->create(['is_active' => true, 'paused_until' => now()->subMinute()]);

        $result = app(PhoneNumberSelector::class)->available();

        $this->assertCount(1, $result);
    }

    public function test_selector_excluye_numero_sin_capacidad_restante(): void
    {
        $phone = PhoneNumber::factory()->create([
            'is_active'   => true,
            'daily_limit' => 2,
            'paused_until' => null,
        ]);

        // Llenar el daily_limit con logs de hoy
        $sentAt = now('America/Mexico_City')->setHour(10)->utc();
        MessageLog::factory()->count(2)->create([
            'phone_number_id' => $phone->id,
            'status'          => 'sent',
            'sent_at'         => $sentAt,
        ]);

        $result = app(PhoneNumberSelector::class)->available();

        $this->assertCount(0, $result);
    }

    public function test_selector_ordena_por_mayor_capacidad_restante(): void
    {
        $sentAt = now('America/Mexico_City')->setHour(10)->utc();

        $phoneA = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 100, 'paused_until' => null]);
        $phoneB = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 100, 'paused_until' => null]);

        // phoneA ya envió 80 — le quedan 20
        MessageLog::factory()->count(80)->create([
            'phone_number_id' => $phoneA->id,
            'status'          => 'sent',
            'sent_at'         => $sentAt,
        ]);

        // phoneB ya envió 10 — le quedan 90
        MessageLog::factory()->count(10)->create([
            'phone_number_id' => $phoneB->id,
            'status'          => 'sent',
            'sent_at'         => $sentAt,
        ]);

        $result = app(PhoneNumberSelector::class)->available();

        $this->assertCount(2, $result);
        // El primero debe ser phoneB (más capacidad restante)
        $this->assertEquals($phoneB->id, $result->first()->id);
    }

    public function test_selector_devuelve_coleccion_vacia_sin_numeros(): void
    {
        $result = app(PhoneNumberSelector::class)->available();

        $this->assertCount(0, $result);
    }

    // ── Balanceo en execute() ────────────────────────────────────────────────

    public function test_execute_distribuye_entre_dos_numeros_en_round_robin(): void
    {
        $this->actingAsOperator();
        Queue::fake();

        $phone1 = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 500, 'paused_until' => null]);
        $phone2 = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 500, 'paused_until' => null]);

        WaTemplate::factory()->create(['name' => 'hello_world', 'status' => 'approved', 'is_active' => true]);
        Contact::factory()->count(4)->create(['status' => 'active']);

        $campaign = Campaign::factory()->create([
            'phone_number_id' => $phone1->id,
            'status'          => 'draft',
            'template_name'   => 'hello_world',
        ]);

        $this->travelTo(now('America/Mexico_City')->startOfWeek()->addDay()->setHour(10));

        $response = $this->postJson("/api/campaigns/{$campaign->id}/execute")
            ->assertStatus(200);

        $this->assertEquals(4, $response->json('data.jobs_dispatched'));
        $this->assertEquals(2, $response->json('data.phone_numbers'));

        Queue::assertCount(4);
    }

    public function test_execute_falla_sin_numeros_disponibles(): void
    {
        $this->actingAsOperator();

        // Número inactivo — el selector no lo toma
        PhoneNumber::factory()->create(['is_active' => false]);

        WaTemplate::factory()->create(['name' => 'hello_world', 'status' => 'approved', 'is_active' => true]);
        Contact::factory()->count(2)->create(['status' => 'active']);

        $campaign = Campaign::factory()->create([
            'phone_number_id' => PhoneNumber::factory()->create(['is_active' => false])->id,
            'status'          => 'draft',
            'template_name'   => 'hello_world',
        ]);

        $this->travelTo(now('America/Mexico_City')->startOfWeek()->addDay()->setHour(10));

        $this->postJson("/api/campaigns/{$campaign->id}/execute")
            ->assertStatus(422)
            ->assertJsonPath('code', 'NO_PHONE_AVAILABLE');
    }

    public function test_execute_usa_numero_con_mas_capacidad_primero(): void
    {
        $this->actingAsOperator();
        Queue::fake();

        $sentAt = now('America/Mexico_City')->setHour(9)->utc();

        // phoneA: daily_limit 10, ya envió 9 → 1 restante
        $phoneA = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 10, 'paused_until' => null]);
        MessageLog::factory()->count(9)->create(['phone_number_id' => $phoneA->id, 'status' => 'sent', 'sent_at' => $sentAt]);

        // phoneB: daily_limit 10, ya envió 0 → 10 restantes
        $phoneB = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 10, 'paused_until' => null]);

        WaTemplate::factory()->create(['name' => 'hello_world', 'status' => 'approved', 'is_active' => true]);
        Contact::factory()->count(2)->create(['status' => 'active']);

        $campaign = Campaign::factory()->create([
            'phone_number_id' => $phoneA->id,
            'status'          => 'draft',
            'template_name'   => 'hello_world',
        ]);

        $this->travelTo(now('America/Mexico_City')->startOfWeek()->addDay()->setHour(10));

        $this->postJson("/api/campaigns/{$campaign->id}/execute")
            ->assertStatus(200)
            ->assertJsonPath('data.jobs_dispatched', 2)
            ->assertJsonPath('data.phone_numbers', 2);

        Queue::assertCount(2);
    }
}
