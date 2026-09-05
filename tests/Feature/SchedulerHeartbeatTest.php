<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\System\SchedulerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Latido del programador de tareas.
 *
 * Una sola línea de cron mueve todo lo automático del sistema, y su muerte es silenciosa:
 * el panel se ve normal mientras dejan de entrar contactos, de subir los límites y de
 * reconciliarse los SMS. Esto es lo que la hace visible.
 */
class SchedulerHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function comoOperador(): User
    {
        return User::factory()->create(['role' => 'operator']);
    }

    // ── El latido ────────────────────────────────────────────────────────────

    public function test_el_scheduler_deja_su_marca(): void
    {
        SchedulerHeartbeat::latir();

        $this->assertNotNull(Setting::get('scheduler_last_run'));
        $this->assertNotNull(SchedulerHeartbeat::ultimoLatido());
    }

    public function test_un_latido_reciente_es_sano(): void
    {
        SchedulerHeartbeat::latir();

        $estado = SchedulerHeartbeat::estado();

        $this->assertTrue($estado['healthy']);
        $this->assertFalse($estado['never_ran']);
        $this->assertSame(0, $estado['minutes_stale']);
    }

    public function test_un_latido_viejo_dispara_la_alarma(): void
    {
        Setting::set('scheduler_last_run', now()->subHours(3)->toIso8601String());

        $estado = SchedulerHeartbeat::estado();

        $this->assertFalse($estado['healthy']);
        $this->assertSame(180, $estado['minutes_stale']);
    }

    public function test_justo_en_el_umbral_todavia_no_alarma(): void
    {
        // 14 minutos: el scheduler late cada minuto, pero un servidor ocupado puede
        // retrasarse. El umbral son 15 para no dar falsas alarmas.
        Setting::set('scheduler_last_run', now()->subMinutes(14)->toIso8601String());

        $this->assertTrue(SchedulerHeartbeat::estado()['healthy']);

        Setting::set('scheduler_last_run', now()->subMinutes(15)->toIso8601String());

        $this->assertFalse(SchedulerHeartbeat::estado()['healthy']);
    }

    public function test_sin_latido_no_alarma_pero_lo_dice(): void
    {
        // Recién desplegado el cron tarda hasta un minuto en dejar la primera marca.
        // Alarmar en cada deploy sería ruido y el operador dejaría de hacerle caso.
        $estado = SchedulerHeartbeat::estado();

        $this->assertTrue($estado['healthy']);
        $this->assertTrue($estado['never_ran']);
        $this->assertNull($estado['last_run']);
    }

    public function test_una_marca_corrupta_no_revienta(): void
    {
        Setting::set('scheduler_last_run', 'esto no es una fecha');

        $estado = SchedulerHeartbeat::estado();

        $this->assertTrue($estado['never_ran']);
    }

    public function test_la_hora_del_ultimo_latido_va_en_hora_de_mexico(): void
    {
        Setting::set('scheduler_last_run', '2026-09-05T18:30:00+00:00');

        // 18:30 UTC son las 12:30 en México.
        $this->assertSame('2026-09-05 12:30', SchedulerHeartbeat::estado()['last_run']);
    }

    // ── El endpoint que consulta el panel ────────────────────────────────────

    public function test_el_panel_puede_consultar_el_estado(): void
    {
        SchedulerHeartbeat::latir();

        $res = $this->actingAs($this->comoOperador())
                    ->getJson('/api/system/scheduler-status');

        $res->assertOk();
        $this->assertTrue($res->json('data.healthy'));
    }

    public function test_el_endpoint_reporta_la_caida(): void
    {
        Setting::set('scheduler_last_run', now()->subHours(5)->toIso8601String());

        $res = $this->actingAs($this->comoOperador())
                    ->getJson('/api/system/scheduler-status');

        $this->assertFalse($res->json('data.healthy'));
        $this->assertSame(300, $res->json('data.minutes_stale'));
    }

    public function test_el_agente_no_consulta_la_salud_del_servidor(): void
    {
        // El agente solo atiende conversaciones, que llegan por webhook y no dependen del
        // cron. Alarmarlo con algo que no le afecta ni puede resolver es ruido.
        $agente = User::factory()->create(['role' => 'agent']);

        $this->actingAs($agente)
             ->getJson('/api/system/scheduler-status')
             ->assertForbidden();
    }

    public function test_sin_sesion_no_se_puede_consultar(): void
    {
        $this->getJson('/api/system/scheduler-status')->assertUnauthorized();
    }
}
