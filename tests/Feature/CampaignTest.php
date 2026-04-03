<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\PhoneNumber;
use App\Models\Tag;
use App\Models\WaTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    // ── Autenticación ────────────────────────────────────────────────────────

    public function test_campaigns_requiere_api_key(): void
    {
        $this->getJson('/api/campaigns')->assertStatus(401);
    }

    // ── Listar ───────────────────────────────────────────────────────────────

    public function test_campaigns_devuelve_lista_paginada(): void
    {
        $this->actingAsOperator();

        Campaign::factory()->count(3)->create();

        $this->getJson('/api/campaigns')
             ->assertStatus(200)
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
        $this->actingAsOperator();

        PhoneNumber::factory()->create(['is_active' => true]);
        WaTemplate::factory()->create([
            'name'      => 'hello_world',
            'status'    => 'approved',
            'is_active' => true,
        ]);

        $this->postJson('/api/campaigns', [
            'name'          => 'Test Campaign',
            'template_name' => 'hello_world',
            'language_code' => 'en_US',
            'body_vars'     => [],
        ])
        ->assertStatus(201)
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('data.name', 'Test Campaign')
        ->assertJsonPath('data.status', 'draft');
    }

    public function test_crear_campana_rechaza_plantilla_no_aprobada(): void
    {
        $this->actingAsOperator();

        PhoneNumber::factory()->create(['is_active' => true]);
        WaTemplate::factory()->create([
            'name'      => 'pending_template',
            'status'    => 'pending',
            'is_active' => true,
        ]);

        $this->postJson('/api/campaigns', [
            'name'          => 'Test',
            'template_name' => 'pending_template',
            'language_code' => 'es_MX',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_TEMPLATE');
    }

    public function test_crear_campana_falla_sin_numero_activo(): void
    {
        $this->actingAsOperator();

        WaTemplate::factory()->create([
            'name'      => 'hello_world',
            'status'    => 'approved',
            'is_active' => true,
        ]);

        $this->postJson('/api/campaigns', [
            'name'          => 'Test',
            'template_name' => 'hello_world',
            'language_code' => 'en_US',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'NO_PHONE_NUMBER');
    }

    // ── Ver campaña ──────────────────────────────────────────────────────────

    public function test_show_campana_existente(): void
    {
        $this->actingAsOperator();

        $campaign = Campaign::factory()->create();

        $this->getJson("/api/campaigns/{$campaign->id}")
             ->assertStatus(200)
             ->assertJsonPath('data.id', $campaign->id);
    }

    public function test_show_campana_inexistente_devuelve_404(): void
    {
        $this->actingAsOperator();

        $this->getJson('/api/campaigns/9999')->assertStatus(404);
    }

    // ── Ejecutar ─────────────────────────────────────────────────────────────

    public function test_execute_encola_jobs_para_contactos_activos(): void
    {
        $this->actingAsOperator();
        Queue::fake();

        $phone    = PhoneNumber::factory()->create(['is_active' => true]);
        $campaign = Campaign::factory()->create([
            'phone_number_id' => $phone->id,
            'status'          => 'draft',
        ]);
        Contact::factory()->count(3)->create(['status' => 'active']);

        // Simular hora dentro de ventana (martes 10AM CST)
        $this->travelTo(now('America/Mexico_City')->startOfWeek()->addDay()->setHour(10));

        $this->postJson("/api/campaigns/{$campaign->id}/execute")
             ->assertStatus(200)
             ->assertJsonPath('data.jobs_dispatched', 3);

        Queue::assertCount(3);
    }

    public function test_execute_rechaza_campana_ya_en_ejecucion(): void
    {
        $this->actingAsOperator();

        $campaign = Campaign::factory()->create(['status' => 'running']);

        $this->travelTo(now('America/Mexico_City')->startOfWeek()->addDay()->setHour(10));

        $this->postJson("/api/campaigns/{$campaign->id}/execute")
             ->assertStatus(422)
             ->assertJsonPath('code', 'INVALID_STATUS');
    }

    public function test_execute_solo_encola_contactos_del_tag_cuando_se_especifica(): void
    {
        $this->actingAsOperator();
        Queue::fake();

        $phone = PhoneNumber::factory()->create(['is_active' => true]);
        $tag   = Tag::create(['name' => 'VIP', 'slug' => 'vip']);

        $contactConTag  = Contact::factory()->create(['status' => 'active']);
        $contactSinTag  = Contact::factory()->create(['status' => 'active']);
        $contactConTag->tags()->attach($tag->id);

        $campaign = Campaign::factory()->create([
            'phone_number_id' => $phone->id,
            'status'          => 'draft',
            'tag_id'          => $tag->id,
        ]);

        $this->travelTo(now('America/Mexico_City')->startOfWeek()->addDay()->setHour(10));

        $this->postJson("/api/campaigns/{$campaign->id}/execute")
            ->assertStatus(200)
            ->assertJsonPath('data.jobs_dispatched', 1);

        Queue::assertCount(1);
    }

    public function test_execute_sin_tag_encola_todos_los_activos(): void
    {
        $this->actingAsOperator();
        Queue::fake();

        $phone = PhoneNumber::factory()->create(['is_active' => true]);
        $tag   = Tag::create(['name' => 'Solo unos', 'slug' => 'solo-unos']);

        $c1 = Contact::factory()->create(['status' => 'active']);
        $c2 = Contact::factory()->create(['status' => 'active']);
        $c1->tags()->attach($tag->id);

        $campaign = Campaign::factory()->create([
            'phone_number_id' => $phone->id,
            'status'          => 'draft',
            'tag_id'          => null,
        ]);

        $this->travelTo(now('America/Mexico_City')->startOfWeek()->addDay()->setHour(10));

        $this->postJson("/api/campaigns/{$campaign->id}/execute")
            ->assertStatus(200)
            ->assertJsonPath('data.jobs_dispatched', 2);

        Queue::assertCount(2);
    }

    public function test_execute_rechaza_fuera_de_horario(): void
    {
        $this->actingAsOperator();

        $phone    = PhoneNumber::factory()->create(['is_active' => true]);
        $campaign = Campaign::factory()->create([
            'phone_number_id' => $phone->id,
            'status'          => 'draft',
        ]);

        // Simular domingo 8AM (fuera de ventana)
        $this->travelTo(now('America/Mexico_City')->startOfWeek()->subDay()->setHour(8));

        $this->postJson("/api/campaigns/{$campaign->id}/execute")
             ->assertStatus(422)
             ->assertJsonPath('code', 'OUTSIDE_SCHEDULE');
    }
}
