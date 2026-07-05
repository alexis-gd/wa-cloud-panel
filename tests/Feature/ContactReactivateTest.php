<?php

namespace Tests\Feature;

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reactivación manual de contactos inalcanzables (Unreachable Bloque B).
 * PUT /api/contacts/{id} con status=active. Solo admin. unreachable es el unico
 * status reversible; opt-out e invalid NO se reactivan por aqui.
 */
class ContactReactivateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_reactiva_contacto_inalcanzable(): void
    {
        $contact = Contact::factory()->create(['status' => 'unreachable']);

        $this->actingAsAdmin()
             ->putJson("/api/contacts/{$contact->id}", ['status' => 'active'])
             ->assertStatus(200)
             ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'status' => 'active']);
    }

    public function test_no_se_puede_reactivar_una_baja(): void
    {
        $contact = Contact::factory()->create(['status' => 'opted_out']);

        $this->actingAsAdmin()
             ->putJson("/api/contacts/{$contact->id}", ['status' => 'active'])
             ->assertStatus(422)
             ->assertJsonPath('code', 'NOT_REACTIVABLE');

        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'status' => 'opted_out']);
    }

    public function test_no_se_puede_reactivar_un_invalido(): void
    {
        $contact = Contact::factory()->create(['status' => 'invalid']);

        $this->actingAsAdmin()
             ->putJson("/api/contacts/{$contact->id}", ['status' => 'active'])
             ->assertStatus(422)
             ->assertJsonPath('code', 'NOT_REACTIVABLE');
    }

    public function test_operator_no_puede_reactivar(): void
    {
        $contact = Contact::factory()->create(['status' => 'unreachable']);

        // La ruta PUT /contacts/{id} es solo admin.
        $this->actingAsOperator()
             ->putJson("/api/contacts/{$contact->id}", ['status' => 'active'])
             ->assertStatus(403);
    }

    public function test_editar_solo_nombre_no_cambia_el_status(): void
    {
        $contact = Contact::factory()->create(['status' => 'unreachable', 'name' => 'Viejo']);

        $this->actingAsAdmin()
             ->putJson("/api/contacts/{$contact->id}", ['name' => 'Nuevo'])
             ->assertStatus(200)
             ->assertJsonPath('data.name', 'Nuevo')
             ->assertJsonPath('data.status', 'unreachable');
    }
}
