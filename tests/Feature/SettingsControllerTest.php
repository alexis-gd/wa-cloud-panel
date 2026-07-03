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

        $response = $this->actingAsSuperAdmin()
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

        $response = $this->actingAsSuperAdmin()
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

        $response = $this->actingAsSuperAdmin()
                         ->getJson('/api/settings/phone-health');

        $response->assertStatus(200)
                 ->assertJsonPath('data.is_paused', true);

        $pausedUntil = $response->json('data.paused_until');
        $this->assertNotNull($pausedUntil);
        // Debe ser formato Y-m-d H:i en CST — no ISO UTC (sin T ni +00:00)
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $pausedUntil);
    }

    public function test_phone_health_sin_numero_activo_retorna_404(): void
    {
        // Sin registros en phone_numbers
        $this->actingAsSuperAdmin()
             ->getJson('/api/settings/phone-health')
             ->assertStatus(404);
    }

    public function test_phone_health_requiere_al_menos_operator(): void
    {
        $this->actingAsAgent()
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

        $this->actingAsSuperAdmin()
             ->getJson('/api/settings/phone-health')
             ->assertStatus(422)
             ->assertJsonPath('status', 'error');
    }

    // ── GET /api/settings/monthly-goal ───────────────────────────────────────

    public function test_get_monthly_goal_devuelve_default_200000(): void
    {
        $this->actingAsSuperAdmin()
             ->getJson('/api/settings/monthly-goal')
             ->assertStatus(200)
             ->assertJsonPath('status', 'ok')
             ->assertJsonPath('data.monthly_goal', 200000);
    }

    public function test_get_monthly_goal_devuelve_valor_guardado(): void
    {
        \App\Models\Setting::set('monthly_goal', 50000);

        $this->actingAsSuperAdmin()
             ->getJson('/api/settings/monthly-goal')
             ->assertJsonPath('data.monthly_goal', 50000);
    }

    public function test_put_monthly_goal_actualiza_valor(): void
    {
        $this->actingAsSuperAdmin()
             ->putJson('/api/settings/monthly-goal', ['monthly_goal' => 150000])
             ->assertStatus(200)
             ->assertJsonPath('data.monthly_goal', 150000);

        $this->assertEquals('150000', \App\Models\Setting::get('monthly_goal'));
    }

    public function test_put_monthly_goal_rechaza_valor_cero(): void
    {
        $this->actingAsSuperAdmin()
             ->putJson('/api/settings/monthly-goal', ['monthly_goal' => 0])
             ->assertStatus(422);
    }

    public function test_monthly_goal_requiere_rol_admin(): void
    {
        $this->actingAsOperator()
             ->getJson('/api/settings/monthly-goal')
             ->assertStatus(403);
    }

    // ── GET/PUT /api/settings/sms-auto-blacklist ─────────────────────────────

    public function test_get_sms_auto_blacklist_devuelve_default_cero(): void
    {
        $this->actingAsSuperAdmin()
             ->getJson('/api/settings/sms-auto-blacklist')
             ->assertStatus(200)
             ->assertJsonPath('status', 'ok')
             ->assertJsonPath('data.sms_auto_blacklist_bounces', 0);
    }

    public function test_put_sms_auto_blacklist_actualiza_valor(): void
    {
        $this->actingAsSuperAdmin()
             ->putJson('/api/settings/sms-auto-blacklist', ['sms_auto_blacklist_bounces' => 3])
             ->assertStatus(200)
             ->assertJsonPath('data.sms_auto_blacklist_bounces', 3);

        $this->assertEquals('3', \App\Models\Setting::get('sms_auto_blacklist_bounces'));
    }

    public function test_put_sms_auto_blacklist_acepta_cero_para_desactivar(): void
    {
        $this->actingAsSuperAdmin()
             ->putJson('/api/settings/sms-auto-blacklist', ['sms_auto_blacklist_bounces' => 0])
             ->assertStatus(200)
             ->assertJsonPath('data.sms_auto_blacklist_bounces', 0);
    }

    public function test_put_sms_auto_blacklist_rechaza_negativo(): void
    {
        $this->actingAsSuperAdmin()
             ->putJson('/api/settings/sms-auto-blacklist', ['sms_auto_blacklist_bounces' => -1])
             ->assertStatus(422);
    }

    public function test_sms_auto_blacklist_requiere_superadmin(): void
    {
        $this->actingAsOperator()
             ->getJson('/api/settings/sms-auto-blacklist')
             ->assertStatus(403);
    }

    // ── POST /api/settings/demo-reset ────────────────────────────────────────

    public function test_demo_reset_quita_paused_until_y_retrocede_cooldown(): void
    {
        $phone = PhoneNumber::factory()->create(['is_active' => true, 'paused_until' => now()->addHour()]);
        MessageLog::factory()->create(['to_number' => '529231311146', 'status' => 'sent', 'sent_at' => now()->subDay()]);

        $response = $this->actingAsSuperAdmin()
                         ->postJson('/api/settings/demo-reset');

        $response->assertStatus(200)->assertJsonPath('status', 'ok');

        $this->assertNull($phone->fresh()->paused_until);
        $this->assertTrue(MessageLog::first()->sent_at->lt(now()->subMonths(11)));
    }

    public function test_demo_reset_requiere_superadmin(): void
    {
        $this->actingAsOperator()
             ->postJson('/api/settings/demo-reset')
             ->assertStatus(403);
    }

    public function test_demo_reset_limpia_bajas_sms(): void
    {
        $contact = \App\Models\Contact::factory()->create([
            'sms_opt_out'      => true,
            'sms_blocked'      => true,
            'sms_invalid'      => true,
            'sms_bounce_count' => 3,
        ]);

        $this->actingAsSuperAdmin()
             ->postJson('/api/settings/demo-reset')
             ->assertStatus(200)
             ->assertJsonPath('status', 'ok');

        $contact->refresh();
        $this->assertFalse($contact->sms_opt_out);
        $this->assertFalse($contact->sms_blocked);
        $this->assertFalse($contact->sms_invalid);
        $this->assertSame(0, $contact->sms_bounce_count);
    }
}
