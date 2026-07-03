<?php

namespace Tests\Feature;

use App\Models\SmsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lista_plantillas_sms(): void
    {
        SmsTemplate::factory()->count(2)->create();

        $this->actingAsOperator()
            ->getJson('/api/sms-templates')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_crea_plantilla_sms(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/sms-templates', ['name' => 'promo_verano', 'body' => 'Prestamaz: promo. STOP para baja.'])
            ->assertStatus(201)
            ->assertJsonPath('data.name', 'promo_verano')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('sms_templates', ['name' => 'promo_verano']);
    }

    public function test_nombre_duplicado_es_rechazado(): void
    {
        SmsTemplate::factory()->create(['name' => 'promo_verano']);

        $this->actingAsAdmin()
            ->postJson('/api/sms-templates', ['name' => 'promo_verano', 'body' => 'otra cosa'])
            ->assertStatus(422);
    }

    public function test_body_requerido_y_limite(): void
    {
        $this->actingAsAdmin()
            ->postJson('/api/sms-templates', ['name' => 'x', 'body' => str_repeat('a', 1001)])
            ->assertStatus(422);
    }

    public function test_admin_actualiza_plantilla(): void
    {
        $t = SmsTemplate::factory()->create(['body' => 'viejo']);

        $this->actingAsAdmin()
            ->putJson("/api/sms-templates/{$t->id}", ['body' => 'nuevo', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.body', 'nuevo')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_admin_elimina_plantilla(): void
    {
        $t = SmsTemplate::factory()->create();

        $this->actingAsAdmin()
            ->deleteJson("/api/sms-templates/{$t->id}")
            ->assertOk();

        $this->assertDatabaseMissing('sms_templates', ['id' => $t->id]);
    }

    public function test_operator_no_puede_crear(): void
    {
        $this->actingAsOperator()
            ->postJson('/api/sms-templates', ['name' => 'x', 'body' => 'y'])
            ->assertStatus(403);
    }
}
