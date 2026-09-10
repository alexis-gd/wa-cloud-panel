<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La bandeja de Conversaciones: orden y mensajes sin leer.
 *
 * El cliente reportaba que "las conversaciones se quedan fijas en el orden que llegaron". Eran
 * dos cosas encimadas: la lista ordenaba por el ULTIMO mensaje de cualquiera, asi que contestar
 * empujaba esa conversacion al primer lugar y tapaba a quien llevaba horas esperando; y no habia
 * forma de ver cual traia mensaje nuevo entre 900 filas identicas.
 */
class ConversationInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PhoneNumber::factory()->create(['is_active' => true]);
    }

    private function mensaje(int $contactId, string $direction, string $body, Carbon $at, ?int $userId = null): Conversation
    {
        $conv = new Conversation([
            'contact_id'   => $contactId,
            'user_id'      => $userId,
            'direction'    => $direction,
            'message_type' => 'text',
            'body'         => $body,
            'status'       => $direction === 'inbound' ? 'received' : 'sent',
            'window_open'  => true,
        ]);
        $conv->created_at = $at;
        $conv->updated_at = $at;
        $conv->save();

        return $conv;
    }

    /** @return array<int, array<string, mixed>> */
    private function lista(): array
    {
        return $this->getJson('/api/conversations')->assertOk()->json('data');
    }

    // -- Orden por ultimo entrante --------------------------------------------

    public function test_ordena_por_el_ultimo_mensaje_del_contacto_no_por_la_respuesta(): void
    {
        $this->actingAsAdmin();

        $a = Contact::factory()->create(['phone' => '521111111111']);
        $b = Contact::factory()->create(['phone' => '521111111112']);

        $this->mensaje($a->id, 'inbound',  'Hola, me interesa', now()->subHours(3));
        $this->mensaje($b->id, 'inbound',  'Buenas tardes',     now()->subHours(2));
        // Le contestamos a A DESPUES de que B escribio: A no debe brincar al primer lugar.
        $this->mensaje($a->id, 'outbound', 'Con gusto',         now()->subMinutes(5));

        $lista = $this->lista();

        $this->assertSame($b->id, $lista[0]['id'], 'Arriba va quien lleva mas tiempo esperando respuesta');
        $this->assertSame($a->id, $lista[1]['id']);
    }

    public function test_un_mensaje_nuevo_del_contacto_si_lo_sube_al_top(): void
    {
        $this->actingAsAdmin();

        $a = Contact::factory()->create(['phone' => '521111111111']);
        $b = Contact::factory()->create(['phone' => '521111111112']);

        $this->mensaje($a->id, 'inbound', 'Primero', now()->subHours(3));
        $this->mensaje($b->id, 'inbound', 'Despues', now()->subHours(2));
        $this->mensaje($a->id, 'inbound', 'Sigo esperando', now()->subMinutes(1));

        $this->assertSame($a->id, $this->lista()[0]['id']);
    }

    /** Una conversacion que abrimos nosotros y nunca contestaron no se pierde al fondo. */
    public function test_una_conversacion_sin_entrantes_se_ordena_por_su_ultimo_mensaje(): void
    {
        $this->actingAsAdmin();

        $conEntrante = Contact::factory()->create(['phone' => '521111111111']);
        $sinEntrante = Contact::factory()->create(['phone' => '521111111112']);

        $this->mensaje($conEntrante->id, 'inbound',  'Hola', now()->subHours(5));
        $this->mensaje($sinEntrante->id, 'outbound', 'Le escribimos', now()->subHour());

        $this->assertSame($sinEntrante->id, $this->lista()[0]['id']);
    }

    // -- Sin leer --------------------------------------------------------------

    public function test_cuenta_los_entrantes_que_nadie_ha_leido(): void
    {
        $this->actingAsAdmin();

        $c = Contact::factory()->create(['phone' => '521111111111', 'conversation_read_at' => now()->subHour()]);

        $this->mensaje($c->id, 'inbound',  'Uno',      now()->subMinutes(30));
        $this->mensaje($c->id, 'inbound',  'Dos',      now()->subMinutes(20));
        $this->mensaje($c->id, 'outbound', 'Contesto', now()->subMinutes(10));

        $this->assertSame(2, $this->lista()[0]['unread_count'], 'Los salientes no cuentan como sin leer');
    }

    public function test_los_mensajes_anteriores_a_la_ultima_lectura_no_cuentan(): void
    {
        $this->actingAsAdmin();

        $c = Contact::factory()->create(['phone' => '521111111111']);

        $this->mensaje($c->id, 'inbound', 'Viejo', now()->subHours(3));
        $c->update(['conversation_read_at' => now()->subHours(2)]);
        $this->mensaje($c->id, 'inbound', 'Nuevo', now()->subMinutes(5));

        $this->assertSame(1, $this->lista()[0]['unread_count']);
    }

    /** Una conversacion que nunca se ha abierto tiene TODO sin leer, no cero. */
    public function test_una_conversacion_nunca_abierta_cuenta_todos_sus_entrantes(): void
    {
        $this->actingAsAdmin();

        $c = Contact::factory()->create(['phone' => '521111111111', 'conversation_read_at' => null]);

        $this->mensaje($c->id, 'inbound', 'Uno', now()->subMinutes(30));
        $this->mensaje($c->id, 'inbound', 'Dos', now()->subMinutes(20));

        $this->assertSame(2, $this->lista()[0]['unread_count']);
    }

    public function test_abrir_la_conversacion_la_deja_leida_para_todo_el_equipo(): void
    {
        $this->actingAsAdmin();

        $c = Contact::factory()->create(['phone' => '521113111461', 'conversation_read_at' => null]);
        $this->mensaje($c->id, 'inbound', 'Hola', now()->subMinutes(5));

        $this->assertSame(1, $this->lista()[0]['unread_count']);

        $this->getJson("/api/conversations/{$c->id}")->assertOk();

        $this->assertSame(0, $this->lista()[0]['unread_count']);

        // Compartido: otro usuario tampoco la ve sin leer.
        $this->actingAsOperator();
        $this->assertSame(0, $this->lista()[0]['unread_count']);
    }

    // -- Quien escribio el ultimo mensaje --------------------------------------

    public function test_dice_quien_mando_el_ultimo_mensaje_cuando_fue_del_equipo(): void
    {
        $agente = User::factory()->create(['name' => 'Joseph Bustamante', 'role' => 'agent']);
        $this->actingAsAdmin();

        $c = Contact::factory()->create(['phone' => '521111111111']);
        $this->mensaje($c->id, 'inbound',  'Hola',      now()->subMinutes(10));
        $this->mensaje($c->id, 'outbound', 'Con gusto', now()->subMinutes(5), $agente->id);

        $fila = $this->lista()[0];

        $this->assertSame('Joseph Bustamante', $fila['last_message_from']);
        $this->assertSame($agente->id, $fila['last_message_user_id']);
    }

    /** Si el ultimo fue del contacto no hay prefijo: ese es el que hay que atender. */
    public function test_no_dice_remitente_cuando_el_ultimo_mensaje_es_del_contacto(): void
    {
        $this->actingAsAdmin();

        $c = Contact::factory()->create(['phone' => '521111111111']);
        $this->mensaje($c->id, 'outbound', 'Le escribimos', now()->subMinutes(10));
        $this->mensaje($c->id, 'inbound',  'Si me interesa', now()->subMinutes(5));

        $this->assertNull($this->lista()[0]['last_message_from']);
    }

    public function test_responder_guarda_quien_contesto(): void
    {
        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldReceive('post')->andReturn([
                'ok'   => true,
                'body' => ['messages' => [['id' => 'wamid.inbox.test']]],
            ]);
        });

        $usuario = User::factory()->create(['name' => 'Alexis Garcia', 'role' => 'operator']);
        $this->actingAs($usuario, 'sanctum');

        $c = Contact::factory()->create(['phone' => '521111111111', 'status' => 'active']);
        $this->mensaje($c->id, 'inbound', 'Hola', now()->subMinutes(5));

        $this->postJson("/api/conversations/{$c->id}/messages", ['body' => 'Con gusto'])
             ->assertStatus(201);

        $this->assertDatabaseHas('conversations', [
            'contact_id' => $c->id,
            'direction'  => 'outbound',
            'user_id'    => $usuario->id,
        ]);
    }
}
