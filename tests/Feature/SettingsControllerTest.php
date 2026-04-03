<?php

namespace Tests\Feature;

use App\Models\MessageLog;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── GET /api/settings/phone-health ───────────────────────────────────────

    public function test_phone_health_retorna_datos_con_quality_rating_green(): void
    {
        $phone = PhoneNumber::factory()->create([
            'is_active'   => true,
            'daily_limit' => 250,
            'paused_until' => null,
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'quality_rating'       => 'GREEN',
                'account_mode'         => 'SANDBOX',
                'display_phone_number' => '+1 555 146 8965',
                'verified_name'        => 'Test Business',
            ], 200),
        ]);

        $response = $this->actingAsAdmin()
                         ->getJson('/api/settings/phone-health');

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'ok')
                 ->assertJsonPath('data.quality_rating', 'GREEN')
                 ->assertJsonPath('data.account_mode', 'SANDBOX')
                 ->assertJsonPath('data.daily_limit', 250)
                 ->assertJsonPath('data.is_paused', false);

        $this->assertArrayHasKey('sent_today', $response->json('data'));
    }

    public function test_phone_health_incluye_sent_today_correcto(): void
    {
        $phone = PhoneNumber::factory()->create(['is_active' => true]);

        // Crear 2 mensajes enviados hoy
        MessageLog::factory()->count(2)->create([
            'phone_number_id' => $phone->id,
            'status'          => 'sent',
            'sent_at'         => now(),
        ]);
        // Mensaje con estado diferente — no debe contar
        MessageLog::factory()->create([
            'phone_number_id' => $phone->id,
            'status'          => 'failed',
            'sent_at'         => now(),
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'quality_rating' => 'GREEN',
                'account_mode'   => 'LIVE',
            ], 200),
        ]);

        $response = $this->actingAsAdmin()
                         ->getJson('/api/settings/phone-health');

        $response->assertStatus(200)
                 ->assertJsonPath('data.sent_today', 2);
    }

    public function test_phone_health_incluye_is_paused_true_cuando_numero_pausado(): void
    {
        PhoneNumber::factory()->create([
            'is_active'    => true,
            'paused_until' => now()->addHour(),
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'quality_rating' => 'RED',
                'account_mode'   => 'SANDBOX',
            ], 200),
        ]);

        $response = $this->actingAsAdmin()
                         ->getJson('/api/settings/phone-health');

        $response->assertStatus(200)
                 ->assertJsonPath('data.is_paused', true);

        $this->assertNotNull($response->json('data.paused_until'));
    }

    public function test_phone_health_sin_numero_activo_retorna_404(): void
    {
        // Sin registros en phone_numbers
        $this->actingAsAdmin()
             ->getJson('/api/settings/phone-health')
             ->assertStatus(404);
    }

    public function test_phone_health_requiere_admin(): void
    {
        $this->actingAsOperator()
             ->getJson('/api/settings/phone-health')
             ->assertStatus(403);
    }

    public function test_phone_health_error_de_meta_retorna_422(): void
    {
        PhoneNumber::factory()->create(['is_active' => true]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'code'    => 467,
                    'message' => 'Token expirado',
                ],
            ], 401),
        ]);

        $this->actingAsAdmin()
             ->getJson('/api/settings/phone-health')
             ->assertStatus(422)
             ->assertJsonPath('status', 'error');
    }
}
