<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\SmsInboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsInboundControllerTest extends TestCase
{
    use RefreshDatabase;

    private function inbound(array $attrs = []): SmsInboundMessage
    {
        return SmsInboundMessage::create(array_merge([
            'from_number' => '529991234567',
            'body'        => 'hola',
            'action'      => null,
            'received_at' => now(),
        ], $attrs));
    }

    public function test_index_lista_respuestas_mas_recientes_primero(): void
    {
        $this->inbound(['body' => 'viejo', 'received_at' => now()->subDay()]);
        $this->inbound(['body' => 'nuevo', 'received_at' => now()]);

        $res = $this->actingAsOperator()->getJson('/api/sms/inbound');

        $res->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.body', 'nuevo');
    }

    public function test_index_incluye_nombre_de_contacto_y_fecha_cst(): void
    {
        $contact = Contact::factory()->create(['phone' => '529991234567', 'name' => 'Juan']);
        $this->inbound(['contact_id' => $contact->id]);

        $res = $this->actingAsOperator()->getJson('/api/sms/inbound');

        $res->assertStatus(200)
            ->assertJsonPath('data.0.contact_name', 'Juan');

        // Fecha en formato CST Y-m-d H:i, no ISO UTC.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $res->json('data.0.received_at'));
    }

    public function test_index_filtra_solo_bajas(): void
    {
        $this->inbound(['body' => 'hola']);
        $this->inbound(['body' => 'STOP', 'action' => 'opt_out']);

        $res = $this->actingAsOperator()->getJson('/api/sms/inbound?opt_out_only=1');

        $res->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'opt_out');
    }

    public function test_index_busca_por_numero_o_texto(): void
    {
        $this->inbound(['from_number' => '529990000000', 'body' => 'quiero informacion']);
        $this->inbound(['from_number' => '521112223344', 'body' => 'otra cosa']);

        $res = $this->actingAsOperator()->getJson('/api/sms/inbound?q=informacion');

        $res->assertStatus(200)->assertJsonPath('meta.total', 1);
    }

    public function test_index_requiere_al_menos_operator(): void
    {
        $this->actingAsAgent()->getJson('/api/sms/inbound')->assertStatus(403);
    }
}
