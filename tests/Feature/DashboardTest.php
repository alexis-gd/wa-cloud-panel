<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_requiere_autenticacion(): void
    {
        $this->getJson('/api/dashboard/stats')->assertStatus(401);
    }

    public function test_stats_devuelve_estructura_correcta(): void
    {
        $this->actingAsOperator()
             ->getJson('/api/dashboard/stats')
             ->assertStatus(200)
             ->assertJsonStructure([
                 'status',
                 'data' => [
                     'stats'    => ['sent', 'delivered', 'read', 'failed'],
                     'contacts' => ['total', 'active', 'opted_out', 'invalid'],
                 ],
             ])
             ->assertJsonPath('status', 'ok');
    }

    public function test_stats_cuenta_contactos_correctamente(): void
    {
        Contact::factory()->count(3)->create(['status' => 'active']);
        Contact::factory()->count(2)->create(['status' => 'opted_out']);
        Contact::factory()->count(1)->create(['status' => 'invalid']);

        $this->actingAsOperator()
             ->getJson('/api/dashboard/stats')
             ->assertJsonPath('data.contacts.total', 6)
             ->assertJsonPath('data.contacts.active', 3)
             ->assertJsonPath('data.contacts.opted_out', 2)
             ->assertJsonPath('data.contacts.invalid', 1);
    }

    public function test_stats_cuenta_mensajes_por_status(): void
    {
        $phone = PhoneNumber::factory()->create();

        MessageLog::factory()->count(4)->create(['phone_number_id' => $phone->id, 'status' => 'sent',      'sent_at' => now()]);
        MessageLog::factory()->count(2)->create(['phone_number_id' => $phone->id, 'status' => 'delivered', 'sent_at' => now()]);
        MessageLog::factory()->count(1)->create(['phone_number_id' => $phone->id, 'status' => 'failed',    'sent_at' => now()]);

        $this->actingAsOperator()
             ->getJson('/api/dashboard/stats')
             ->assertJsonPath('data.stats.sent', 4)
             ->assertJsonPath('data.stats.delivered', 2)
             ->assertJsonPath('data.stats.failed', 1)
             ->assertJsonPath('data.stats.read', 0);
    }

    public function test_messages_devuelve_20_por_pagina(): void
    {
        $phone = PhoneNumber::factory()->create();
        MessageLog::factory()->count(25)->create(['phone_number_id' => $phone->id, 'sent_at' => now()]);

        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/messages?per_page=20')
                    ->assertStatus(200);

        $this->assertCount(20, $res->json('data'));
        $this->assertEquals(25, $res->json('meta.total'));
    }

    // ── GET /api/dashboard/daily-stats ────────────────────────────────────────

    public function test_daily_stats_requiere_autenticacion(): void
    {
        $this->getJson('/api/dashboard/daily-stats')->assertStatus(401);
    }

    public function test_daily_stats_devuelve_dias_del_mes_en_curso(): void
    {
        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/daily-stats')
                    ->assertStatus(200)
                    ->assertJsonPath('status', 'ok');

        // La serie debe tener exactamente tantos días como el día de hoy en el mes
        $expectedDays = (int) now('America/Mexico_City')->day;
        $this->assertCount($expectedDays, $res->json('data'));
    }

    public function test_daily_stats_estructura_por_dia(): void
    {
        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/daily-stats')
                    ->assertStatus(200);

        $first = $res->json('data.0');
        $this->assertArrayHasKey('day',       $first);
        $this->assertArrayHasKey('sent',      $first);
        $this->assertArrayHasKey('delivered', $first);
        $this->assertArrayHasKey('read',      $first);
        $this->assertArrayHasKey('failed',    $first);
    }

    public function test_daily_stats_cuenta_mensajes_de_hoy(): void
    {
        $phone = PhoneNumber::factory()->create();
        MessageLog::factory()->count(3)->create([
            'phone_number_id' => $phone->id,
            'status'          => 'sent',
            'sent_at'         => now(),
        ]);
        MessageLog::factory()->count(2)->create([
            'phone_number_id' => $phone->id,
            'status'          => 'delivered',
            'sent_at'         => now(),
        ]);

        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/daily-stats')
                    ->assertStatus(200);

        // El controller agrupa por DATE(CONVERT_TZ(created_at, '+00:00', '-06:00'))
        $today    = now('America/Mexico_City')->format('Y-m-d');
        $todayRow = collect($res->json('data'))->firstWhere('day', $today);

        $this->assertNotNull($todayRow, "No se encontró el día de hoy en la serie");
        $this->assertEquals(3, $todayRow['sent']);
        $this->assertEquals(2, $todayRow['delivered']);
    }

    public function test_daily_stats_dias_sin_mensajes_tienen_ceros(): void
    {
        // Sin ningún MessageLog — todos los días deben ser ceros
        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/daily-stats')
                    ->assertStatus(200);

        foreach ($res->json('data') as $day) {
            $this->assertEquals(0, $day['sent']);
            $this->assertEquals(0, $day['delivered']);
            $this->assertEquals(0, $day['read']);
            $this->assertEquals(0, $day['failed']);
        }
    }

    public function test_daily_stats_no_incluye_mensajes_del_mes_pasado(): void
    {
        $phone = PhoneNumber::factory()->create();
        $old   = new MessageLog(MessageLog::factory()->make([
            'phone_number_id' => $phone->id,
            'status'          => 'sent',
            'sent_at'         => now()->subMonths(1),
        ])->toArray());
        $old->created_at = now()->subMonths(1);
        $old->updated_at = now()->subMonths(1);
        $old->save();

        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/daily-stats')
                    ->assertStatus(200);

        $total = collect($res->json('data'))->sum('sent');
        $this->assertEquals(0, $total, "No deben aparecer mensajes del mes anterior");
    }

    public function test_daily_stats_agente_no_tiene_acceso(): void
    {
        $this->actingAsAgent()
             ->getJson('/api/dashboard/daily-stats')
             ->assertStatus(403);
    }

    // ── GET /api/dashboard/stats — bloque monthly ─────────────────────────────

    public function test_stats_incluye_bloque_monthly(): void
    {
        $this->actingAsOperator()
             ->getJson('/api/dashboard/stats')
             ->assertStatus(200)
             ->assertJsonStructure([
                 'data' => [
                     'monthly' => [
                         'sent', 'capacity', 'pct',
                         'working_days_total', 'working_days_remaining',
                         'daily_limit', 'month_label',
                     ],
                 ],
             ]);
    }

    public function test_stats_monthly_cuenta_solo_mensajes_del_mes_en_curso(): void
    {
        $phone = PhoneNumber::factory()->create();

        // Este mes — deben contarse
        MessageLog::factory()->count(5)->create([
            'phone_number_id' => $phone->id,
            'status'          => 'sent',
            'sent_at'         => now(),
        ]);

        // Mes pasado — NO deben contarse
        $oldLog = MessageLog::factory()->make([
            'phone_number_id' => $phone->id,
            'status'          => 'sent',
            'sent_at'         => now()->subMonths(1),
        ]);
        $oldLog->created_at = now()->subMonths(1);
        $oldLog->updated_at = now()->subMonths(1);
        $oldLog->save();

        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/stats')
                    ->assertStatus(200);

        $this->assertEquals(5, $res->json('data.monthly.sent'));
    }

    public function test_stats_monthly_capacidad_usa_daily_limit_de_numeros_activos(): void
    {
        // Número activo con 250/día
        PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 250]);

        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/stats')
                    ->assertStatus(200);

        $dailyLimit = $res->json('data.monthly.daily_limit');
        $capacity   = $res->json('data.monthly.capacity');
        $wdTotal    = $res->json('data.monthly.working_days_total');

        // capacity = daily_limit × working_days_total
        $this->assertEquals(250, $dailyLimit);
        $this->assertEquals($dailyLimit * $wdTotal, $capacity);
        $this->assertGreaterThan(0, $wdTotal);
    }

    public function test_stats_monthly_pct_se_calcula_sobre_capacidad(): void
    {
        $phone = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 250]);

        // Enviamos justo la mitad de la capacidad de un día
        MessageLog::factory()->count(125)->create([
            'phone_number_id' => $phone->id,
            'status'          => 'sent',
            'sent_at'         => now(),
        ]);

        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/stats')
                    ->assertStatus(200);

        // pct = 125 / (250 × working_days_total) × 100
        $capacity = $res->json('data.monthly.capacity');
        $expected = round(125 / $capacity * 100, 1);
        $this->assertEquals($expected, $res->json('data.monthly.pct'));
    }

    // ── GET /api/dashboard/monthly-history ───────────────────────────────────

    public function test_monthly_history_requiere_autenticacion(): void
    {
        $this->getJson('/api/dashboard/monthly-history')->assertStatus(401);
    }

    public function test_monthly_history_devuelve_6_meses(): void
    {
        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/monthly-history')
                    ->assertStatus(200)
                    ->assertJsonPath('status', 'ok');

        $this->assertCount(6, $res->json('data'));
    }

    public function test_monthly_history_estructura_por_mes(): void
    {
        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/monthly-history')
                    ->assertStatus(200);

        $first = $res->json('data.0');
        $this->assertArrayHasKey('month',       $first);
        $this->assertArrayHasKey('month_label', $first);
        $this->assertArrayHasKey('sent',        $first);
        $this->assertArrayHasKey('capacity',    $first);
        $this->assertArrayHasKey('pct',         $first);
    }

    public function test_monthly_history_ultimo_mes_es_mes_en_curso(): void
    {
        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/monthly-history')
                    ->assertStatus(200);

        $lastMonth = last($res->json('data'));
        $this->assertEquals(now('America/Mexico_City')->format('Y-m'), $lastMonth['month']);
    }

    public function test_monthly_history_cuenta_mensajes_del_mes_correcto(): void
    {
        $phone = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 250]);

        // 3 mensajes este mes
        MessageLog::factory()->count(3)->create([
            'phone_number_id' => $phone->id,
            'status'          => 'sent',
            'sent_at'         => now(),
        ]);

        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/monthly-history')
                    ->assertStatus(200);

        $lastMonth = last($res->json('data'));
        $this->assertEquals(3, $lastMonth['sent']);
    }
}
