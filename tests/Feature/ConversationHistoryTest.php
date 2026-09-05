<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reasignación de conversaciones e historial con autoría (P2).
 *
 * Sirve para cambios de turno: quién tuvo la conversación, quién la movió y qué movimiento
 * fue. La tabla es un libro que solo crece - nada se borra ni se edita, porque el historial
 * es justo lo que se quiere medir.
 */
class ConversationHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function contactoConChat(): Contact
    {
        $contact = Contact::create([
            'phone'  => '529231311146',
            'name'   => 'Prueba',
            'status' => 'active',
            'source' => 'manual',
        ]);

        Conversation::create([
            'contact_id'   => $contact->id,
            'direction'    => 'inbound',
            'message_type' => 'text',
            'body'         => 'Hola',
            'status'       => 'received',
        ]);

        return $contact;
    }

    private function agente(string $nombre): User
    {
        return User::factory()->create(['name' => $nombre, 'role' => 'agent', 'is_active' => true]);
    }

    // ── Autoría ──────────────────────────────────────────────────────────────

    public function test_asignar_guarda_quien_lo_hizo(): void
    {
        $contact = $this->contactoConChat();
        $agente  = $this->agente('Ana');
        $admin   = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)
             ->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $agente->id])
             ->assertOk();

        $this->assertDatabaseHas('conversation_assignments', [
            'contact_id'     => $contact->id,
            'user_id'        => $agente->id,
            'assigned_by_id' => $admin->id,
            'action'         => ConversationAssignment::ACTION_MANUAL,
        ]);
    }

    public function test_pasarla_de_un_agente_a_otro_se_registra_como_reasignacion(): void
    {
        $contact = $this->contactoConChat();
        $ana     = $this->agente('Ana');
        $beto    = $this->agente('Beto');
        $admin   = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $ana->id])->assertOk();
        $this->actingAs($admin)->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $beto->id])->assertOk();

        // La primera es "Asignada"; la segunda, que le quita el chat a Ana, es "Reasignada".
        $this->assertDatabaseHas('conversation_assignments', [
            'contact_id' => $contact->id,
            'user_id'    => $beto->id,
            'action'     => ConversationAssignment::ACTION_REASSIGN,
        ]);
    }

    public function test_reasignar_al_mismo_agente_se_rechaza(): void
    {
        $contact = $this->contactoConChat();
        $ana     = $this->agente('Ana');
        $admin   = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $ana->id])->assertOk();

        // Sin esto, cada clic de más ensuciaría el historial con movimientos que no movieron nada.
        $this->actingAs($admin)
             ->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $ana->id])
             ->assertStatus(422)
             ->assertJsonPath('code', 'ALREADY_ASSIGNED');

        $this->assertSame(1, ConversationAssignment::where('contact_id', $contact->id)->count());
    }

    public function test_el_agente_que_se_la_toma_queda_como_autor(): void
    {
        $contact = $this->contactoConChat();
        $ana     = $this->agente('Ana');

        $this->actingAs($ana)->postJson("/api/conversations/{$contact->id}/claim")->assertOk();

        $this->assertDatabaseHas('conversation_assignments', [
            'contact_id'     => $contact->id,
            'user_id'        => $ana->id,
            'assigned_by_id' => $ana->id,
            'action'         => ConversationAssignment::ACTION_CLAIM,
        ]);
    }

    // ── Soltar ───────────────────────────────────────────────────────────────

    public function test_soltar_la_deja_sin_agente_sin_perder_el_historial(): void
    {
        $contact = $this->contactoConChat();
        $ana     = $this->agente('Ana');
        $admin   = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $ana->id])->assertOk();
        $this->actingAs($admin)->postJson("/api/conversations/{$contact->id}/release")->assertOk();

        $this->assertNull($contact->fresh()->currentAssignment()?->user_id);
        $this->assertSame(2, ConversationAssignment::where('contact_id', $contact->id)->count());
    }

    public function test_una_conversacion_suelta_no_le_cuenta_a_nadie(): void
    {
        // El reparto automático cuenta la carga de cada agente por su fila más reciente:
        // una liberación tiene user_id null y por lo tanto no debe sumarle a nadie.
        $contact = $this->contactoConChat();
        $ana     = $this->agente('Ana');
        $admin   = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $ana->id])->assertOk();
        $this->actingAs($admin)->postJson("/api/conversations/{$contact->id}/release")->assertOk();

        $actual = ConversationAssignment::where('contact_id', $contact->id)->orderByDesc('id')->first();

        $this->assertNull($actual->user_id);
        $this->assertSame(ConversationAssignment::ACTION_RELEASE, $actual->action);
    }

    public function test_el_agente_deja_de_ver_una_conversacion_que_le_quitaron(): void
    {
        $contact = $this->contactoConChat();
        $ana     = $this->agente('Ana');
        $beto    = $this->agente('Beto');
        $admin   = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $ana->id])->assertOk();
        $this->assertCount(1, $this->actingAs($ana)->getJson('/api/conversations')->assertOk()->json('data'));

        $this->actingAs($admin)->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $beto->id])->assertOk();

        $this->assertCount(0, $this->actingAs($ana)->getJson('/api/conversations')->assertOk()->json('data'));
        $this->assertCount(1, $this->actingAs($beto)->getJson('/api/conversations')->assertOk()->json('data'));
    }

    // ── Historial ────────────────────────────────────────────────────────────

    public function test_el_historial_llega_del_mas_reciente_al_mas_viejo(): void
    {
        $contact = $this->contactoConChat();
        $ana     = $this->agente('Ana');
        $beto    = $this->agente('Beto');
        $admin   = User::factory()->create(['name' => 'Jefe', 'role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $ana->id])->assertOk();
        $this->actingAs($admin)->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $beto->id])->assertOk();

        $data = $this->actingAs($admin)->getJson("/api/conversations/{$contact->id}/history")->assertOk()->json('data');

        $this->assertCount(2, $data);
        $this->assertSame('Beto', $data[0]['agent']);
        $this->assertSame('Ana',  $data[1]['agent']);
        $this->assertSame('Jefe', $data[0]['by']);
    }

    public function test_el_historial_trae_el_movimiento_en_espanol(): void
    {
        $contact = $this->contactoConChat();
        $ana     = $this->agente('Ana');
        $admin   = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $ana->id])->assertOk();

        $fila = $this->actingAs($admin)->getJson("/api/conversations/{$contact->id}/history")->assertOk()->json('data.0');

        // Los identificadores viajan en inglés, pero al operador nunca le llegan crudos.
        $this->assertSame('Asignada', $fila['action_label']);
        $this->assertNotSame($fila['action'], $fila['action_label']);
    }

    public function test_un_movimiento_del_sistema_no_tiene_autor(): void
    {
        $contact = $this->contactoConChat();
        $ana     = $this->agente('Ana');
        $admin   = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        app(\App\Services\AssignmentService::class)->autoAssign($contact->id);

        $fila = $this->actingAs($admin)->getJson("/api/conversations/{$contact->id}/history")->assertOk()->json('data.0');

        $this->assertNull($fila['by']);
        $this->assertSame('Asignación automática', $fila['action_label']);
        $this->assertSame($ana->name, $fila['agent']);
    }

    public function test_la_fecha_del_historial_va_en_hora_de_mexico(): void
    {
        $contact = $this->contactoConChat();
        $ana     = $this->agente('Ana');
        $admin   = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $ana->id])->assertOk();

        $fila = $this->actingAs($admin)->getJson("/api/conversations/{$contact->id}/history")->assertOk()->json('data.0');

        $esperado = now('America/Mexico_City')->format('Y-m-d H:i');

        $this->assertSame($esperado, $fila['at']);
    }

    public function test_el_historial_de_un_contacto_sin_movimientos_va_vacio(): void
    {
        $contact = $this->contactoConChat();
        $admin   = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)
             ->getJson("/api/conversations/{$contact->id}/history")
             ->assertOk()
             ->assertJsonPath('data', []);
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_un_agente_no_puede_reasignar_ni_ver_el_historial(): void
    {
        $contact = $this->contactoConChat();
        $ana     = $this->agente('Ana');

        $this->actingAs($ana)->getJson("/api/conversations/{$contact->id}/history")->assertStatus(403);
        $this->actingAs($ana)->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $ana->id])->assertStatus(403);
        $this->actingAs($ana)->postJson("/api/conversations/{$contact->id}/release")->assertStatus(403);
    }

    public function test_el_operador_si_puede_reasignar_y_ver_el_historial(): void
    {
        $contact  = $this->contactoConChat();
        $ana      = $this->agente('Ana');
        $operador = User::factory()->create(['role' => 'operator', 'is_active' => true]);

        $this->actingAs($operador)
             ->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $ana->id])
             ->assertOk();

        $this->actingAs($operador)->getJson("/api/conversations/{$contact->id}/history")->assertOk();
    }

    public function test_el_historial_de_un_contacto_inexistente_da_404(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAs($admin)->getJson('/api/conversations/999999/history')->assertStatus(404);
    }
}
