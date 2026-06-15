<?php

namespace Tests\Feature;

use App\Models\WaTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
