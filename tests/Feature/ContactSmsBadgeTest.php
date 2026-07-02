<?php

namespace Tests\Feature;

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactSmsBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_expone_flags_sms_al_frontend(): void
    {
        $this->actingAsOperator();
        Contact::factory()->create(['status' => 'active', 'sms_opt_out' => true]);

        $this->getJson('/api/contacts')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'sms_opt_out', 'sms_blocked', 'sms_invalid']]]);
    }

    public function test_filtro_sms_blocked_devuelve_solo_bajas_sms(): void
    {
        $this->actingAsOperator();
        $optOut  = Contact::factory()->create(['phone' => '521110000001', 'status' => 'active', 'sms_opt_out' => true]);
        $blocked = Contact::factory()->create(['phone' => '521110000002', 'status' => 'active', 'sms_blocked' => true]);
        $invalid = Contact::factory()->create(['phone' => '521110000003', 'status' => 'active', 'sms_invalid' => true]);
        $clean   = Contact::factory()->create(['phone' => '521110000004', 'status' => 'active']);

        $ids = collect($this->getJson('/api/contacts?sms_blocked=1')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($optOut->id, $ids);
        $this->assertContains($blocked->id, $ids);
        $this->assertContains($invalid->id, $ids);
        $this->assertNotContains($clean->id, $ids);
    }

    public function test_sin_filtro_incluye_contactos_sin_baja_sms(): void
    {
        $this->actingAsOperator();
        $clean = Contact::factory()->create(['status' => 'active']);

        $ids = collect($this->getJson('/api/contacts')->assertOk()->json('data'))
            ->pluck('id')->all();

        $this->assertContains($clean->id, $ids);
    }
}
