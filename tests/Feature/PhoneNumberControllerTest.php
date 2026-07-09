<?php

namespace Tests\Feature;

use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneNumberControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'EAA-system-user-token-abcdefghij'; // >= 20 chars

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
            'token'           => self::TOKEN,
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('status', 'ok')
                 ->assertJsonPath('data.display_name', 'Número campañas 1')
                 ->assertJsonPath('data.is_active', true)
                 ->assertJsonPath('data.quality_rating', 'GREEN')
                 ->assertJsonPath('data.daily_limit', 250); // límite de warm-up fijo, no lo pone el usuario

        // Nunca se filtra el token ni el phone_number_id en la respuesta.
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertStringNotContainsString('1082360764952377', $response->getContent());

        $this->assertDatabaseHas('phone_numbers', [
            'phone_number_id' => '1082360764952377',
            'is_active'       => true,
            'daily_limit'     => 250,
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
            'phone_number_id' => '99999999999',
            'waba_id'         => '88888888888',
            'token'           => self::TOKEN,
        ])->assertStatus(422)
          ->assertJsonPath('code', 'META_VERIFY_FAILED');

        $this->assertDatabaseMissing('phone_numbers', ['phone_number_id' => '99999999999']);
    }

    public function test_store_rechaza_phone_number_id_no_numerico(): void
    {
        $this->actingAsSuperAdmin()->postJson('/api/phone-numbers', [
            'display_name'    => 'Malo',
            'phone_number_id' => 'ABC123',
            'waba_id'         => '1236630511398211',
            'token'           => self::TOKEN,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('phone_numbers', ['display_name' => 'Malo']);
    }

    public function test_store_rechaza_numero_duplicado(): void
    {
        $this->fakeMetaOk();
        PhoneNumber::factory()->create(['phone_number_id' => '1082360764952377']);

        $this->actingAsSuperAdmin()->postJson('/api/phone-numbers', [
            'display_name'    => 'Repetido',
            'phone_number_id' => '1082360764952377',
            'waba_id'         => '1236630511398211',
            'token'           => self::TOKEN,
        ])->assertStatus(422)
          ->assertJsonPath('code', 'DUPLICATE');
    }

    public function test_store_reutiliza_el_token_de_la_misma_waba(): void
    {
        $this->fakeMetaOk();
        PhoneNumber::factory()->create([
            'waba_id' => '1236630511398211',
            'token'   => 'EAA-shared-waba-token-abcdefghij',
        ]);

        // Sin token en el body: se reutiliza el de la WABA existente.
        $this->actingAsSuperAdmin()->postJson('/api/phone-numbers', [
            'display_name'    => 'Segundo número',
            'phone_number_id' => '2082360764952378',
            'waba_id'         => '1236630511398211',
        ])->assertStatus(201);

        $created = PhoneNumber::where('phone_number_id', '2082360764952378')->first();
        $this->assertSame('EAA-shared-waba-token-abcdefghij', $created->token);
    }

    public function test_store_reutiliza_el_token_del_numero_activo_si_es_otra_waba(): void
    {
        $this->fakeMetaOk();
        PhoneNumber::factory()->create([
            'is_active' => true,
            'waba_id'   => '1111111111111111',
            'token'     => 'EAA-account-token-abcdefghij',
        ]);

        // WABA distinta y sin token: cae al token del número activo (el de la cuenta).
        $this->actingAsSuperAdmin()->postJson('/api/phone-numbers', [
            'display_name'    => 'Número de otra WABA',
            'phone_number_id' => '4082360764952380',
            'waba_id'         => '2222222222222222',
        ])->assertStatus(201);

        $created = PhoneNumber::where('phone_number_id', '4082360764952380')->first();
        $this->assertSame('EAA-account-token-abcdefghij', $created->token);
    }

    public function test_store_exige_token_de_cuenta_si_no_hay_ningun_numero(): void
    {
        // BD sin números: no hay token de cuenta del cual reutilizar.
        $this->actingAsSuperAdmin()->postJson('/api/phone-numbers', [
            'display_name'    => 'Número sin token',
            'phone_number_id' => '3082360764952379',
            'waba_id'         => '9999999999999999',
        ])->assertStatus(422)
          ->assertJsonPath('code', 'TOKEN_REQUIRED');
    }

    public function test_index_lista_sin_exponer_token_ni_ids_de_meta(): void
    {
        PhoneNumber::factory()->create([
            'display_name'    => 'Número A',
            'phone_number_id' => '111222333',
            'token'           => 'super-secret-token-abcdefghij',
        ]);

        $response = $this->actingAsSuperAdmin()->getJson('/api/phone-numbers');

        $response->assertStatus(200)
                 ->assertJsonPath('data.0.display_name', 'Número A');

        $this->assertStringNotContainsString('super-secret-token-abcdefghij', $response->getContent());
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
