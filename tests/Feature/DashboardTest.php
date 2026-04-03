<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_daily_stats_devuelve_serie_de_14_dias(): void
    {
        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/daily-stats')
                    ->assertStatus(200)
                    ->assertJsonPath('status', 'ok');

        $this->assertCount(14, $res->json('data'));
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

        // El controller agrupa por DATE(CONVERT_TZ(created_at, '+00:00', '-06:00')),
        // así que "hoy" hay que consultarlo en CST, no en UTC (fallaría entre 00:00-06:00 UTC).
        $today = now('America/Mexico_City')->format('Y-m-d');
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

    public function test_daily_stats_no_incluye_mensajes_fuera_de_14_dias(): void
    {
        $phone = PhoneNumber::factory()->create();
        $old   = new MessageLog(MessageLog::factory()->make([
            'phone_number_id' => $phone->id,
            'status'          => 'sent',
            'sent_at'         => now()->subDays(15),
        ])->toArray());
        $old->created_at = now()->subDays(15);
        $old->updated_at = now()->subDays(15);
        $old->save();

        $res = $this->actingAsOperator()
                    ->getJson('/api/dashboard/daily-stats')
                    ->assertStatus(200);

        $total = collect($res->json('data'))->sum('sent');
        $this->assertEquals(0, $total, "No deben aparecer mensajes de hace más de 14 días");
    }

    public function test_daily_stats_agente_no_tiene_acceso(): void
    {
        // daily-stats está en el grupo role:admin,operator — agent no puede
        $this->actingAsAgent()
             ->getJson('/api/dashboard/daily-stats')
             ->assertStatus(403);
    }
}
