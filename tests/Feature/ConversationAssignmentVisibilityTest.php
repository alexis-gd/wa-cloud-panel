<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un agente atiende MUCHAS conversaciones a la vez. La bitacora
 * `conversation_assignments` siempre lo permitio (es append-only, sin unique), pero el
 * listado cargaba las asignaciones con `->limit(1)` sobre un hasMany: eso no da "una por
 * contacto", limita la consulta ENTERA a una sola fila. Resultado: de toda la lista un
 * unico contacto salia con agente y el resto "Sin asignar", asi que asignar una segunda
 * conversacion parecia soltar la primera.
 */
class ConversationAssignmentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function contactoConChat(string $nombre): Contact
    {
        $contact = Contact::factory()->create(['status' => 'active', 'name' => $nombre]);

        Conversation::create([
            'contact_id'   => $contact->id,
            'direction'    => 'inbound',
            'message_type' => 'text',
            'body'         => 'Hola',
            'status'       => 'received',
            'window_open'  => true,
        ]);

        return $contact;
    }

    private function asignar(Contact $contact, User $agent, ?string $cuando = null): void
    {
        ConversationAssignment::create([
            'contact_id'  => $contact->id,
            'user_id'     => $agent->id,
            'assigned_at' => $cuando ?? now(),
        ]);
    }

    public function test_un_agente_puede_tener_varias_conversaciones_asignadas_a_la_vez(): void
    {
        $this->actingAsAdmin();

        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);

        $uno  = $this->contactoConChat('Uno');
        $dos  = $this->contactoConChat('Dos');
        $tres = $this->contactoConChat('Tres');

        $this->asignar($uno,  $agent);
        $this->asignar($dos,  $agent);
        $this->asignar($tres, $agent);

        $data = collect($this->getJson('/api/conversations')->assertOk()->json('data'));

        foreach ([$uno, $dos, $tres] as $contact) {
            $item = $data->firstWhere('id', $contact->id);

            $this->assertNotNull(
                $item['assigned_to'],
                "El contacto {$contact->name} salio 'Sin asignar' aunque tiene agente.",
            );
            $this->assertSame($agent->id, $item['assigned_to']['id']);
        }
    }

    public function test_asignar_una_conversacion_no_suelta_las_anteriores(): void
    {
        $this->actingAsAdmin();

        $agent    = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $anterior = $this->contactoConChat('Anterior');
        $nueva    = $this->contactoConChat('Nueva');

        $this->postJson("/api/conversations/{$anterior->id}/assign", ['user_id' => $agent->id])->assertOk();
        $this->postJson("/api/conversations/{$nueva->id}/assign",    ['user_id' => $agent->id])->assertOk();

        $data = collect($this->getJson('/api/conversations')->assertOk()->json('data'));

        $this->assertSame($agent->id, $data->firstWhere('id', $anterior->id)['assigned_to']['id']);
        $this->assertSame($agent->id, $data->firstWhere('id', $nueva->id)['assigned_to']['id']);
    }

    public function test_agentes_distintos_conservan_cada_uno_sus_conversaciones(): void
    {
        $this->actingAsAdmin();

        $ana  = User::factory()->create(['role' => 'agent', 'is_active' => true, 'name' => 'Ana']);
        $beto = User::factory()->create(['role' => 'agent', 'is_active' => true, 'name' => 'Beto']);

        $deAna  = $this->contactoConChat('De Ana');
        $deBeto = $this->contactoConChat('De Beto');

        $this->asignar($deAna,  $ana);
        $this->asignar($deBeto, $beto);

        $data = collect($this->getJson('/api/conversations')->assertOk()->json('data'));

        $this->assertSame($ana->id,  $data->firstWhere('id', $deAna->id)['assigned_to']['id']);
        $this->assertSame($beto->id, $data->firstWhere('id', $deBeto->id)['assigned_to']['id']);
    }

    /**
     * Dos reasignaciones dentro del mismo segundo empatan en `assigned_at` (la columna
     * guarda al segundo). Sin desempate por id, la lista podia mostrar al agente viejo
     * mientras el filtro por agente - que ya usaba MAX(id) - mostraba al nuevo.
     */
    public function test_reasignacion_en_el_mismo_segundo_gana_la_mas_reciente(): void
    {
        $this->actingAsAdmin();

        $viejo  = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $nuevo  = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $contact = $this->contactoConChat('Reasignado');

        $mismoInstante = now()->format('Y-m-d H:i:s');
        $this->asignar($contact, $viejo, $mismoInstante);
        $this->asignar($contact, $nuevo, $mismoInstante);

        $data = collect($this->getJson('/api/conversations')->assertOk()->json('data'));

        $this->assertSame($nuevo->id, $data->firstWhere('id', $contact->id)['assigned_to']['id']);
    }

    public function test_el_detalle_del_chat_coincide_con_el_listado(): void
    {
        $this->actingAsAdmin();

        $viejo   = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $nuevo   = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $contact = $this->contactoConChat('Coherencia');

        $mismoInstante = now()->format('Y-m-d H:i:s');
        $this->asignar($contact, $viejo, $mismoInstante);
        $this->asignar($contact, $nuevo, $mismoInstante);

        $enElListado = collect($this->getJson('/api/conversations')->assertOk()->json('data'))
            ->firstWhere('id', $contact->id)['assigned_to']['id'];

        $enElDetalle = $this->getJson("/api/conversations/{$contact->id}")
            ->assertOk()
            ->json('data.contact.assigned_to.id');

        $this->assertSame($enElListado, $enElDetalle);
    }
}
