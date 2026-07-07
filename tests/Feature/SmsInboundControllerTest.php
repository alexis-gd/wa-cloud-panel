<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\SmsInboundMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsInboundControllerTest extends TestCase
{
    use RefreshDatabase;

    // Crea un entrante. Por default es de un contacto (así se muestra en la vista agrupada).
    // Los tests que prueban el ruido de operadora pasan contact_id null explícito.
    private function inbound(array $attrs = []): SmsInboundMessage
    {
        if (! array_key_exists('contact_id', $attrs)) {
            $phone = $attrs['from_number'] ?? '529991234567';
            $attrs['contact_id'] = Contact::firstOrCreate(
                ['phone' => $phone],
                Contact::factory()->make(['phone' => $phone])->getAttributes(),
            )->id;
        }

        return SmsInboundMessage::create(array_merge([
            'from_number' => '529991234567',
            'body'        => 'hola',
            'action'      => null,
            'received_at' => now(),
        ], $attrs));
    }

    public function test_index_agrupa_por_contacto_con_ultimo_mensaje_y_conteo(): void
    {
        // Dos respuestas del MISMO contacto -> 1 grupo, count 2, último = más reciente.
        $this->inbound(['body' => 'viejo', 'received_at' => now()->subDay()]);
        $this->inbound(['body' => 'nuevo', 'received_at' => now()]);

        $res = $this->actingAsOperator()->getJson('/api/sms/inbound');

        $res->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('meta.total', 1)          // 1 grupo (1 contacto)
            ->assertJsonPath('data.0.count', 2)
            ->assertJsonPath('data.0.last_body', 'nuevo');

        // El hilo trae los 2 mensajes, más reciente primero.
        $this->assertCount(2, $res->json('data.0.messages'));
        $this->assertSame('nuevo', $res->json('data.0.messages.0.body'));
    }

    public function test_index_ordena_grupos_por_ultima_respuesta(): void
    {
        $c1 = Contact::factory()->create(['phone' => '529990000001']);
        $c2 = Contact::factory()->create(['phone' => '529990000002']);

        $this->inbound(['contact_id' => $c1->id, 'from_number' => '529990000001', 'body' => 'viejo', 'received_at' => now()->subHour()]);
        $this->inbound(['contact_id' => $c2->id, 'from_number' => '529990000002', 'body' => 'nuevo', 'received_at' => now()]);

        $res = $this->actingAsOperator()->getJson('/api/sms/inbound');

        $res->assertStatus(200)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.contact_id', $c2->id); // el de respuesta más reciente arriba
    }

    public function test_index_incluye_nombre_de_contacto_y_fecha_cst(): void
    {
        $contact = Contact::factory()->create(['phone' => '529991234567', 'name' => 'Juan']);
        $this->inbound(['contact_id' => $contact->id]);

        $res = $this->actingAsOperator()->getJson('/api/sms/inbound');

        $res->assertStatus(200)
            ->assertJsonPath('data.0.contact_name', 'Juan');

        // Fecha en formato CST Y-m-d H:i, no ISO UTC.
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $res->json('data.0.last_received_at'));
    }

    public function test_index_resumen_de_grupo_prioriza_baja_sobre_interes(): void
    {
        $this->inbound(['body' => 'SI', 'action' => 'interested', 'received_at' => now()->subMinutes(5)]);
        $this->inbound(['body' => 'STOP', 'action' => 'opt_out', 'received_at' => now()]);

        $res = $this->actingAsOperator()->getJson('/api/sms/inbound');

        $res->assertStatus(200)
            ->assertJsonPath('data.0.summary_action', 'opt_out'); // la baja manda
    }

    public function test_index_filtra_por_accion_bajas(): void
    {
        $this->inbound(['from_number' => '529990000001', 'contact_id' => Contact::factory()->create(['phone' => '529990000001'])->id, 'body' => 'hola']);
        $this->inbound(['from_number' => '529990000002', 'contact_id' => Contact::factory()->create(['phone' => '529990000002'])->id, 'body' => 'STOP', 'action' => 'opt_out']);

        $res = $this->actingAsOperator()->getJson('/api/sms/inbound?action=opt_out');

        $res->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.summary_action', 'opt_out');
    }

    public function test_index_filtra_por_accion_interesados(): void
    {
        $this->inbound(['from_number' => '529990000001', 'contact_id' => Contact::factory()->create(['phone' => '529990000001'])->id, 'body' => 'hola']);
        $this->inbound(['from_number' => '529990000002', 'contact_id' => Contact::factory()->create(['phone' => '529990000002'])->id, 'body' => 'SI', 'action' => 'interested']);

        $res = $this->actingAsOperator()->getJson('/api/sms/inbound?action=interested');

        $res->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.summary_action', 'interested');
    }

    public function test_index_busca_por_numero_o_texto(): void
    {
        $this->inbound(['from_number' => '529990000000', 'contact_id' => Contact::factory()->create(['phone' => '529990000000'])->id, 'body' => 'quiero informacion']);
        $this->inbound(['from_number' => '521112223344', 'contact_id' => Contact::factory()->create(['phone' => '521112223344'])->id, 'body' => 'otra cosa']);

        $res = $this->actingAsOperator()->getJson('/api/sms/inbound?q=informacion');

        $res->assertStatus(200)->assertJsonPath('meta.total', 1);
    }

    public function test_index_excluye_ruido_sin_contacto(): void
    {
        $this->inbound(['body' => 'quiero informacion']);
        $this->inbound(['contact_id' => null, 'from_number' => 'UNOTV', 'body' => 'promo']);

        $res = $this->actingAsOperator()->getJson('/api/sms/inbound');

        $res->assertStatus(200)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.last_body', 'quiero informacion');
    }

    public function test_index_requiere_al_menos_operator(): void
    {
        $this->actingAsAgent()->getJson('/api/sms/inbound')->assertStatus(403);
    }
}
