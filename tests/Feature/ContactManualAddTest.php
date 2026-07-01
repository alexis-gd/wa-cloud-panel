<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\Setting;
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
}
