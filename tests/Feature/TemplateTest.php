<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\PhoneNumber;
use App\Models\WaTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_templates_requires_auth(): void
    {
        $this->getJson('/api/templates')->assertStatus(401);
    }

    public function test_templates_returns_list(): void
    {
        WaTemplate::factory()->create(['name' => 'tpl_uno', 'status' => 'approved', 'is_active' => true]);
        WaTemplate::factory()->create(['name' => 'tpl_dos', 'status' => 'approved', 'is_active' => true]);

        $this->actingAsOperator()
             ->getJson('/api/templates')
             ->assertStatus(200)
             ->assertJsonPath('status', 'ok')
             ->assertJsonCount(2, 'data');
    }

    public function test_admin_puede_crear_template(): void
    {
        $this->actingAsAdmin()
             ->postJson('/api/templates', [
                 'name'          => 'prestamaz_interes_v1',
                 'language_code' => 'es_MX',
                 'category'      => 'MARKETING',
             ])
             ->assertStatus(201)
             ->assertJsonPath('status', 'ok')
             ->assertJsonPath('data.name', 'prestamaz_interes_v1');

        $this->assertDatabaseHas('wa_templates', ['name' => 'prestamaz_interes_v1', 'status' => 'approved']);
    }

    public function test_operator_no_puede_crear_template(): void
    {
        $this->actingAsOperator()
             ->postJson('/api/templates', [
                 'name'          => 'tpl_test',
                 'language_code' => 'es_MX',
                 'category'      => 'MARKETING',
             ])
             ->assertStatus(403);
    }

    public function test_no_se_pueden_crear_templates_duplicados(): void
    {
        WaTemplate::factory()->create(['name' => 'tpl_existente']);

        $this->actingAsAdmin()
             ->postJson('/api/templates', [
                 'name'          => 'tpl_existente',
                 'language_code' => 'es_MX',
                 'category'      => 'MARKETING',
             ])
             ->assertStatus(422);
    }

    public function test_admin_puede_desactivar_template(): void
    {
        $tpl = WaTemplate::factory()->create(['is_active' => true]);

        $this->actingAsAdmin()
             ->putJson("/api/templates/{$tpl->id}", ['is_active' => false])
             ->assertStatus(200)
             ->assertJsonPath('data.is_active', false);
    }

    public function test_admin_puede_eliminar_template(): void
    {
        $tpl = WaTemplate::factory()->create();

        $this->actingAsAdmin()
             ->deleteJson("/api/templates/{$tpl->id}")
             ->assertStatus(200)
             ->assertJsonPath('status', 'ok');

        $this->assertDatabaseMissing('wa_templates', ['id' => $tpl->id]);
    }

    public function test_operator_no_puede_enviar_prueba(): void
    {
        $this->actingAsOperator()
             ->postJson('/api/templates/send-test', [
                 'template_name' => 'hello_world',
                 'language_code' => 'en_US',
                 'to'            => '529231311146',
             ])
             ->assertStatus(403);
    }

    // ── Envío de prueba: es un mensaje real, respeta la baja ─────────────────
    // Se salta dedup/cooldown/horario a propósito (para eso es una prueba), pero nunca la
    // baja: mandarle marketing a quien pidió STOP viola la política de Meta y la ley.

    public function test_prueba_rechaza_contacto_dado_de_baja(): void
    {
        Http::fake();
        PhoneNumber::factory()->create(['is_active' => true]);
        Contact::factory()->create(['phone' => '529231311146', 'status' => 'opted_out']);

        $response = $this->actingAsAdmin()
             ->postJson('/api/templates/send-test', [
                 'template_name' => 'hello_world',
                 'language_code' => 'en_US',
                 'to'            => '529231311146',
             ])
             ->assertStatus(422)
             ->assertJsonPath('code', 'CONTACT_NOT_SENDABLE');

        $this->assertStringContainsString('baja', $response->json('message'));
        Http::assertNothingSent();
        $this->assertDatabaseCount('message_log', 0);
    }

    public function test_prueba_rechaza_numero_que_no_es_contacto(): void
    {
        Http::fake();
        PhoneNumber::factory()->create(['is_active' => true]);

        $this->actingAsAdmin()
             ->postJson('/api/templates/send-test', [
                 'template_name' => 'hello_world',
                 'language_code' => 'en_US',
                 'to'            => '529999999999',
             ])
             ->assertStatus(422)
             ->assertJsonPath('code', 'CONTACT_NOT_FOUND');

        Http::assertNothingSent();
    }

    public function test_prueba_envia_a_contacto_activo(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.TEST']],
            ], 200),
        ]);
        PhoneNumber::factory()->create(['is_active' => true]);
        Contact::factory()->create(['phone' => '529231311146', 'status' => 'active']);

        $this->actingAsAdmin()
             ->postJson('/api/templates/send-test', [
                 'template_name' => 'hello_world',
                 'language_code' => 'en_US',
                 'to'            => '529231311146',
             ])
             ->assertStatus(200);

        $this->assertDatabaseCount('message_log', 1);
    }

    // ── Visibilidad de plantillas (solo superadmin) ──────────────────────────

    public function test_superadmin_puede_ocultar_y_mostrar_template(): void
    {
        $tpl = WaTemplate::factory()->create(['is_hidden' => false]);

        $this->actingAsSuperAdmin()
             ->putJson("/api/templates/{$tpl->id}/visibility", ['is_hidden' => true])
             ->assertStatus(200)
             ->assertJsonPath('data.is_hidden', true);

        $this->assertDatabaseHas('wa_templates', ['id' => $tpl->id, 'is_hidden' => true]);

        $this->actingAsSuperAdmin()
             ->putJson("/api/templates/{$tpl->id}/visibility", ['is_hidden' => false])
             ->assertStatus(200)
             ->assertJsonPath('data.is_hidden', false);
    }

    public function test_admin_no_puede_cambiar_visibilidad(): void
    {
        $tpl = WaTemplate::factory()->create();

        $this->actingAsAdmin()
             ->putJson("/api/templates/{$tpl->id}/visibility", ['is_hidden' => true])
             ->assertStatus(403);
    }

    public function test_operator_no_ve_templates_ocultas(): void
    {
        WaTemplate::factory()->create(['name' => 'visible_tpl', 'is_hidden' => false]);
        WaTemplate::factory()->create(['name' => 'oculta_tpl',  'is_hidden' => true]);

        $res = $this->actingAsOperator()->getJson('/api/templates')->assertStatus(200);

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('visible_tpl', $res->json('data.0.name'));
    }

    public function test_superadmin_si_ve_templates_ocultas(): void
    {
        WaTemplate::factory()->create(['name' => 'visible_tpl', 'is_hidden' => false]);
        WaTemplate::factory()->create(['name' => 'oculta_tpl',  'is_hidden' => true]);

        $this->actingAsSuperAdmin()
             ->getJson('/api/templates')
             ->assertStatus(200)
             ->assertJsonCount(2, 'data');
    }
}
