<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactManualAddTest extends TestCase
{
    use RefreshDatabase;

    // ── POST /api/contacts (alta manual) ─────────────────────────────────────

    public function test_crea_contacto_manual(): void
    {
        $this->actingAsOperator();

        $this->postJson('/api/contacts', ['phone' => '9231311146', 'name' => 'Juan'])
            ->assertStatus(201)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.phone', '529231311146') // normalizado
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('contacts', ['phone' => '529231311146', 'source' => 'manual']);
    }

    public function test_rechaza_formato_invalido(): void
    {
        $this->actingAsOperator();

        $this->postJson('/api/contacts', ['phone' => '123'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_PHONE');
    }

    public function test_rechaza_duplicado_con_estado(): void
    {
        $this->actingAsOperator();
        Contact::factory()->create(['phone' => '529231311146', 'status' => 'opted_out']);

        $this->postJson('/api/contacts', ['phone' => '529231311146'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DUPLICATE')
            ->assertJsonPath('data.contact_status', 'opted_out')
            ->assertJsonPath('data.blocked', true);
    }

    public function test_alta_requiere_auth(): void
    {
        $this->postJson('/api/contacts', ['phone' => '9231311146'])->assertStatus(401);
    }

    // ── GET /api/contacts/check ──────────────────────────────────────────────

    public function test_check_numero_nuevo_es_deliverable(): void
    {
        $this->actingAsOperator();

        $this->getJson('/api/contacts/check?phone=9231311146')
            ->assertOk()
            ->assertJsonPath('data.valid_format', true)
            ->assertJsonPath('data.exists', false)
            ->assertJsonPath('data.deliverable', true);
    }

    public function test_check_formato_invalido(): void
    {
        $this->actingAsOperator();

        $this->getJson('/api/contacts/check?phone=123')
            ->assertOk()
            ->assertJsonPath('data.valid_format', false)
            ->assertJsonPath('data.deliverable', false);
    }

    public function test_check_opted_out_esta_bloqueado(): void
    {
        $this->actingAsOperator();
        Contact::factory()->create(['phone' => '529231311146', 'status' => 'opted_out']);

        $this->getJson('/api/contacts/check?phone=529231311146')
            ->assertOk()
            ->assertJsonPath('data.exists', true)
            ->assertJsonPath('data.blocked', true)
            ->assertJsonPath('data.deliverable', false);
    }

    public function test_check_detecta_cooldown(): void
    {
        $this->actingAsOperator();
        Setting::set('cooldown_days', '30');
        $phone   = PhoneNumber::factory()->create();
        $contact = Contact::factory()->create(['phone' => '529231311146', 'status' => 'active']);

        MessageLog::create([
            'phone_number_id' => $phone->id,
            'to_number'       => $contact->phone,
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => 'sent',
            'sent_at'         => now()->subDays(5),
        ]);

        $this->getJson("/api/contacts/check?phone={$contact->phone}")
            ->assertOk()
            ->assertJsonPath('data.cooldown_active', true)
            ->assertJsonPath('data.deliverable', false);
    }

    public function test_check_detecta_enviado_hoy(): void
    {
        $this->actingAsOperator();
        $phone   = PhoneNumber::factory()->create();
        $contact = Contact::factory()->create(['phone' => '529231311146', 'status' => 'active']);

        MessageLog::create([
            'phone_number_id' => $phone->id,
            'to_number'       => $contact->phone,
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => 'delivered',
            'sent_at'         => now(),
        ]);

        $this->getJson("/api/contacts/check?phone={$contact->phone}")
            ->assertOk()
            ->assertJsonPath('data.sent_today', true)
            ->assertJsonPath('data.deliverable', false);
    }

    // ── Números borrados (soft delete) ────────────────────────────────────

    /**
     * `contacts.phone` tiene indice UNIQUE y el modelo usa SoftDeletes, asi que un contacto
     * borrado SIGUE ocupando el numero. Antes del arreglo esto era un 500: el chequeo previo
     * no veia la fila borrada, decia "disponible" en verde, y el insert chocaba contra el
     * UNIQUE. Es la misma trampa que ya habia mordido en la importacion de CSV.
     */
    public function test_restaura_un_numero_borrado_en_vez_de_reventar(): void
    {
        $this->actingAsOperator();
        $contacto = Contact::factory()->create(['phone' => '529231311146']);
        $contacto->delete();

        $this->postJson('/api/contacts', ['phone' => '9231311146'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('restored', true)
            ->assertJsonPath('data.phone', '529231311146');

        // La misma fila, no una nueva: el UNIQUE no permitiria dos.
        $this->assertDatabaseCount('contacts', 1);
        $this->assertNotSoftDeleted('contacts', ['id' => $contacto->id]);
    }

    /** Restaurar trae de vuelta la ficha completa, no un contacto en blanco. */
    public function test_restaurar_conserva_su_historial(): void
    {
        $this->actingAsOperator();
        $tag      = Tag::create(['name' => 'Interesados']);
        $contacto = Contact::factory()->create([
            'phone' => '529231311146',
            'notes' => 'Pidio informes en sucursal',
        ]);
        $contacto->tags()->attach($tag->id);
        $contacto->delete();

        $this->postJson('/api/contacts', ['phone' => '9231311146'])
            ->assertStatus(200)
            ->assertJsonPath('data.notes', 'Pidio informes en sucursal')
            ->assertJsonPath('data.tags.0.id', $tag->id);
    }

    /**
     * **Lo que no se puede deshacer nunca.** Restaurar devuelve la ficha, no el permiso: quien
     * se dio de baja sigue de baja aunque alguien lo vuelva a agregar. Si esto se rompiera, el
     * panel estaria reviviendo contactos que pidieron no ser contactados.
     */
    public function test_restaurar_no_revive_una_baja(): void
    {
        $this->actingAsOperator();
        $contacto = Contact::factory()->create([
            'phone'            => '529231311146',
            'status'           => 'opted_out',
            'opted_out_at'     => now(),
            'opted_out_source' => 'whatsapp_text',
        ]);
        $contacto->delete();

        $this->postJson('/api/contacts', ['phone' => '9231311146'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'opted_out');

        $this->assertDatabaseHas('contacts', [
            'phone'  => '529231311146',
            'status' => 'opted_out',
        ]);
    }

    public function test_el_nombre_que_escribe_el_operador_gana_al_restaurar(): void
    {
        $this->actingAsOperator();
        $contacto = Contact::factory()->create(['phone' => '529231311146', 'name' => 'Nombre viejo']);
        $contacto->delete();

        $this->postJson('/api/contacts', ['phone' => '9231311146', 'name' => 'Nombre nuevo'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Nombre nuevo');
    }

    public function test_restaurar_sin_nombre_conserva_el_que_tenia(): void
    {
        $this->actingAsOperator();
        $contacto = Contact::factory()->create(['phone' => '529231311146', 'name' => 'Nombre viejo']);
        $contacto->delete();

        $this->postJson('/api/contacts', ['phone' => '9231311146'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'Nombre viejo');
    }

    /**
     * El aviso en vivo es lo que el operador lee ANTES de dar Guardar. Si dice "disponible"
     * sobre un numero borrado, lo manda derecho al error. El aviso y el insert tienen que
     * mirar lo mismo.
     */
    public function test_check_avisa_que_el_numero_esta_borrado(): void
    {
        $this->actingAsOperator();
        $contacto = Contact::factory()->create(['phone' => '529231311146']);
        $contacto->delete();

        $this->getJson('/api/contacts/check?phone=9231311146')
            ->assertOk()
            ->assertJsonPath('data.exists', true)
            ->assertJsonPath('data.deleted', true)
            // Un contacto borrado no esta en ninguna campana, asi que no es "entregable".
            ->assertJsonPath('data.deliverable', false);
    }

    public function test_check_de_un_numero_vivo_no_lo_marca_borrado(): void
    {
        $this->actingAsOperator();
        Contact::factory()->create(['phone' => '529231311146']);

        $this->getJson('/api/contacts/check?phone=9231311146')
            ->assertOk()
            ->assertJsonPath('data.exists', true)
            ->assertJsonPath('data.deleted', false);
    }
}
