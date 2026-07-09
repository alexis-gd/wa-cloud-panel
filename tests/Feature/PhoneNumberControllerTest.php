<?php

namespace Tests\Feature;

use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneNumberControllerTest extends TestCase
{
    use RefreshDatabase;

    private function fakeMetaOk(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'display_phone_number'     => '+52 669 101 0211',
                'verified_name'            => 'Prestamaz',
                'code_verification_status' => 'VERIFIED',
                'name_status'              => 'APPROVED',
                'quality_rating'           => 'GREEN',
            ], 200),
        ]);
    }

    public function test_store_verifica_contra_meta_y_crea_el_numero(): void
    {
        $this->fakeMetaOk();

        $response = $this->actingAsSuperAdmin()->postJson('/api/phone-numbers', [
            'display_name'    => 'Número campañas 1',
            'phone_number_id' => '1082360764952377',
            'waba_id'         => '1236630511398211',
            'token'           => 'EAA-secret-token',
            'daily_limit'     => 250,
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('status', 'ok')
                 ->assertJsonPath('data.display_name', 'Número campañas 1')
                 ->assertJsonPath('data.is_active', true)
                 ->assertJsonPath('data.quality_rating', 'GREEN');

        // Nunca se filtra el token ni el phone_number_id en la respuesta.
        $this->assertStringNotContainsString('EAA-secret-token', $response->getContent());
        $this->assertStringNotContainsString('1082360764952377', $response->getContent());

        $this->assertDatabaseHas('phone_numbers', [
            'phone_number_id' => '1082360764952377',
            'is_active'       => true,
        ]);
    }

    public function test_store_falla_si_meta_rechaza_y_no_crea_fila(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token', 'code' => 190],
            ], 401),
        ]);

        $this->actingAsSuperAdmin()->postJson('/api/phone-numbers', [
            'display_name'    => 'Número malo',
            'phone_number_id' => '999',
            'waba_id'         => '888',
            'token'           => 'bad-token',
            'daily_limit'     => 250,
        ])->assertStatus(422)
          ->assertJsonPath('code', 'META_VERIFY_FAILED');

        $this->assertDatabaseMissing('phone_numbers', ['phone_number_id' => '999']);
    }

    public function test_store_rechaza_numero_duplicado(): void
    {
        $this->fakeMetaOk();
        PhoneNumber::factory()->create(['phone_number_id' => '1082360764952377']);

        $this->actingAsSuperAdmin()->postJson('/api/phone-numbers', [
            'display_name'    => 'Repetido',
            'phone_number_id' => '1082360764952377',
            'waba_id'         => '1236630511398211',
            'token'           => 'EAA-token',
            'daily_limit'     => 250,
        ])->assertStatus(422)
          ->assertJsonPath('code', 'DUPLICATE');
    }

    public function test_index_lista_sin_exponer_token_ni_ids_de_meta(): void
    {
        PhoneNumber::factory()->create([
            'display_name'    => 'Número A',
            'phone_number_id' => '111222333',
            'token'           => 'super-secret',
        ]);

        $response = $this->actingAsSuperAdmin()->getJson('/api/phone-numbers');

        $response->assertStatus(200)
                 ->assertJsonPath('data.0.display_name', 'Número A');

        $this->assertStringNotContainsString('super-secret', $response->getContent());
        $this->assertStringNotContainsString('111222333', $response->getContent());
    }

    public function test_update_activa_y_desactiva_el_numero(): void
    {
        $phone = PhoneNumber::factory()->create(['is_active' => true]);

        $this->actingAsSuperAdmin()->putJson("/api/phone-numbers/{$phone->id}", [
            'is_active'   => false,
            'daily_limit' => 500,
        ])->assertStatus(200)
          ->assertJsonPath('data.is_active', false)
          ->assertJsonPath('data.daily_limit', 500);

        $this->assertDatabaseHas('phone_numbers', ['id' => $phone->id, 'is_active' => false, 'daily_limit' => 500]);
    }

    public function test_verify_reconsulta_meta_para_un_numero_existente(): void
    {
        $this->fakeMetaOk();
        $phone = PhoneNumber::factory()->create();

        $this->actingAsSuperAdmin()->postJson("/api/phone-numbers/{$phone->id}/verify")
             ->assertStatus(200)
             ->assertJsonPath('data.code_verification_status', 'VERIFIED')
             ->assertJsonPath('data.name_status', 'APPROVED');
    }

    public function test_admin_no_superadmin_no_puede_gestionar_numeros(): void
    {
        $this->actingAsAdmin()->getJson('/api/phone-numbers')->assertStatus(403);
    }
}
