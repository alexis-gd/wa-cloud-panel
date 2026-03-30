<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsCooldownTest extends TestCase
{
    use RefreshDatabase;

    // ── GET /api/settings/cooldown ────────────────────────────────────────────

    public function test_get_cooldown_requiere_admin(): void
    {
        $this->actingAsOperator()
             ->getJson('/api/settings/cooldown')
             ->assertStatus(403);
    }

    public function test_get_cooldown_devuelve_valor_de_bd(): void
    {
        Setting::set('cooldown_days', '20');

        $this->actingAsAdmin()
             ->getJson('/api/settings/cooldown')
             ->assertStatus(200)
             ->assertJsonPath('status', 'ok')
             ->assertJsonPath('data.cooldown_days', 20);
    }

    public function test_get_cooldown_usa_default_si_no_existe_en_bd(): void
    {
        // Sin fila en settings — debe devolver 30 (default)
        $this->actingAsAdmin()
             ->getJson('/api/settings/cooldown')
             ->assertStatus(200)
             ->assertJsonPath('data.cooldown_days', 30);
    }

    // ── PUT /api/settings/cooldown ────────────────────────────────────────────

    public function test_update_cooldown_requiere_admin(): void
    {
        $this->actingAsOperator()
             ->putJson('/api/settings/cooldown', ['cooldown_days' => 15])
             ->assertStatus(403);
    }

    public function test_update_cooldown_guarda_valor(): void
    {
        $this->actingAsAdmin()
             ->putJson('/api/settings/cooldown', ['cooldown_days' => 15])
             ->assertStatus(200)
             ->assertJsonPath('data.cooldown_days', 15);

        $this->assertSame('15', Setting::get('cooldown_days'));
    }

    public function test_update_cooldown_rechaza_menos_de_7(): void
    {
        $this->actingAsAdmin()
             ->putJson('/api/settings/cooldown', ['cooldown_days' => 3])
             ->assertStatus(422);
    }

    public function test_update_cooldown_rechaza_mas_de_365(): void
    {
        $this->actingAsAdmin()
             ->putJson('/api/settings/cooldown', ['cooldown_days' => 400])
             ->assertStatus(422);
    }
}
