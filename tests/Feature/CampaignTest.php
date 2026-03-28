<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\PhoneNumber;
use App\Models\WaTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey = 'test-api-key';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.api_key' => $this->apiKey]);
    }

    private function headers(): array
    {
        return ['X-API-Key' => $this->apiKey];
    }

    // ── Autenticación ────────────────────────────────────────────────────────

    public function test_campaigns_requiere_api_key(): void
    {
        $this->getJson('/api/campaigns')->assertStatus(401);
    }

    // ── Listar ───────────────────────────────────────────────────────────────

    public function test_campaigns_devuelve_lista_paginada(): void
    {
        Campaign::factory()->count(3)->create();

        $response = $this->withHeaders($this->headers())->getJson('/api/campaigns');

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'status',
                     'data',
                     'meta' => ['total', 'page', 'per_page'],
                 ])
                 ->assertJsonPath('status', 'ok')
                 ->assertJsonPath('meta.total', 3);
    }

    // ── Crear ────────────────────────────────────────────────────────────────

    public function test_crear_campana_con_datos_validos(): void
    {
        PhoneNumber::factory()->create(['is_active' => true]);
        WaTemplate::factory()->create([
            'name'      => 'hello_world',
            'status'    => 'approved',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->headers())->postJson('/api/campaigns', [
            'name'          => 'Test Campaign',
            'template_name' => 'hello_world',
            'language_code' => 'en_US',
            'body_vars'     => [],
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('status', 'ok')
                 ->assertJsonPath('data.name', 'Test Campaign')
                 ->assertJsonPath('data.status', 'draft');
    }

    public function test_crear_campana_rechaza_plantilla_no_aprobada(): void
    {
        PhoneNumber::factory()->create(['is_active' => true]);
        WaTemplate::factory()->create([
            'name'      => 'pending_template',
            'status'    => 'pending',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->headers())->postJson('/api/campaigns', [
            'name'          => 'Test',
            'template_name' => 'pending_template',
            'language_code' => 'es_MX',
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('code', 'INVALID_TEMPLATE');
    }

    public function test_crear_campana_falla_sin_numero_activo(): void
    {
        WaTemplate::factory()->create([
            'name'      => 'hello_world',
            'status'    => 'approved',
            'is_active' => true,
        ]);

        $response = $this->withHeaders($this->headers())->postJson('/api/campaigns', [
            'name'          => 'Test',
            'template_name' => 'hello_world',
            'language_code' => 'en_US',
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('code', 'NO_PHONE_NUMBER');
    }

    // ── Ver campaña ──────────────────────────────────────────────────────────

    public function test_show_campana_existente(): void
    {
        $campaign = Campaign::factory()->create();

        $this->withHeaders($this->headers())
             ->getJson("/api/campaigns/{$campaign->id}")
             ->assertStatus(200)
             ->assertJsonPath('data.id', $campaign->id);
    }

    public function test_show_campana_inexistente_devuelve_404(): void
    {
        $this->withHeaders($this->headers())
             ->getJson('/api/campaigns/9999')
             ->assertStatus(404);
    }

    // ── Ejecutar ─────────────────────────────────────────────────────────────

    public function test_execute_encola_jobs_para_contactos_activos(): void
    {
        Queue::fake();

        $phone    = PhoneNumber::factory()->create(['is_active' => true]);
        $campaign = Campaign::factory()->create([
            'phone_number_id' => $phone->id,
            'status'          => 'draft',
        ]);
        Contact::factory()->count(3)->create(['status' => 'active']);

        // Simular hora dentro de ventana (martes 10AM CST)
        $this->travelTo(now('America/Mexico_City')->startOfWeek()->addDay()->setHour(10));

        $response = $this->withHeaders($this->headers())
                         ->postJson("/api/campaigns/{$campaign->id}/execute");

        $response->assertStatus(200)
                 ->assertJsonPath('data.jobs_dispatched', 3);

        Queue::assertCount(3);
    }

    public function test_execute_rechaza_campana_ya_en_ejecucion(): void
    {
        $campaign = Campaign::factory()->create(['status' => 'running']);

        $this->travelTo(now('America/Mexico_City')->startOfWeek()->addDay()->setHour(10));

        $this->withHeaders($this->headers())
             ->postJson("/api/campaigns/{$campaign->id}/execute")
             ->assertStatus(422)
             ->assertJsonPath('code', 'INVALID_STATUS');
    }

    public function test_execute_rechaza_fuera_de_horario(): void
    {
        $phone    = PhoneNumber::factory()->create(['is_active' => true]);
        $campaign = Campaign::factory()->create([
            'phone_number_id' => $phone->id,
            'status'          => 'draft',
        ]);

        // Simular domingo 8AM (fuera de ventana)
        $this->travelTo(now('America/Mexico_City')->startOfWeek()->subDay()->setHour(8));

        $this->withHeaders($this->headers())
             ->postJson("/api/campaigns/{$campaign->id}/execute")
             ->assertStatus(422)
             ->assertJsonPath('code', 'OUTSIDE_SCHEDULE');
    }
}
