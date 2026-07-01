<?php

namespace Tests\Feature;

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    // ── DELETE /api/contacts/{id} (soft delete) ──────────────────────────────

    public function test_admin_puede_soft_delete(): void
    {
        $this->actingAsAdmin();
        $contact = Contact::factory()->create(['status' => 'active']);

        $this->deleteJson("/api/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        // Sigue en BD pero con deleted_at
        $this->assertSoftDeleted('contacts', ['id' => $contact->id]);
    }

    public function test_soft_deleted_no_aparece_en_index(): void
    {
        $this->actingAsAdmin();
        $visible = Contact::factory()->create(['status' => 'active']);
        $deleted = Contact::factory()->create(['status' => 'active']);
        $deleted->delete();

        $ids = collect($this->getJson('/api/contacts')->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($deleted->id));
    }

    public function test_soft_deleted_excluido_de_active_scope(): void
    {
        Contact::factory()->create(['status' => 'active']);
        $deleted = Contact::factory()->create(['status' => 'active']);
        $deleted->delete();

        // El scope active() (que usan las campañas) no lo incluye
        $this->assertEquals(1, Contact::active()->count());
    }

    public function test_operator_no_puede_eliminar(): void
    {
        $this->actingAsOperator();
        $contact = Contact::factory()->create(['status' => 'active']);

        $this->deleteJson("/api/contacts/{$contact->id}")->assertStatus(403);

        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'deleted_at' => null]);
    }

    public function test_eliminar_requiere_auth(): void
    {
        $contact = Contact::factory()->create();
        $this->deleteJson("/api/contacts/{$contact->id}")->assertStatus(401);
    }

    // ── POST /api/contacts/{id}/opt-out (acción separada) ────────────────────

    public function test_opt_out_marca_contacto(): void
    {
        $this->actingAsOperator();
        $contact = Contact::factory()->create(['status' => 'active']);

        $this->postJson("/api/contacts/{$contact->id}/opt-out")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'status' => 'opted_out']);
    }
}
