<?php

namespace Tests\Feature;

use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WarmupPhoneNumbersTest extends TestCase
{
    use RefreshDatabase;

    /** Crea N mensajes "enviados" ayer (mediodía CST) para el número. */
    private function sentYesterday(PhoneNumber $phone, int $count): void
    {
        $when = Carbon::now('America/Mexico_City')->subDay()->setTime(12, 0)->utc();

        MessageLog::factory()->count($count)->create([
            'phone_number_id' => $phone->id,
            'status'          => 'sent',
            'sent_at'         => $when,
        ]);
    }

    public function test_sube_el_limite_si_uso_mas_del_50_por_ciento(): void
    {
        Setting::set('wa_portfolio_daily_limit', 'TIER_1K'); // techo 1000
        $phone = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 250]);
        $this->sentYesterday($phone, 200); // 200 >= 125 (50% de 250)

        $this->artisan('wa:warmup-numbers')->assertSuccessful();

        $this->assertEquals(500, $phone->fresh()->daily_limit); // min(250*2, 1000)
    }

    public function test_no_sube_si_uso_menos_del_50_por_ciento(): void
    {
        Setting::set('wa_portfolio_daily_limit', 'TIER_1K');
        $phone = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 250]);
        $this->sentYesterday($phone, 100); // < 125

        $this->artisan('wa:warmup-numbers')->assertSuccessful();

        $this->assertEquals(250, $phone->fresh()->daily_limit);
    }

    public function test_no_sube_arriba_del_techo_del_portfolio(): void
    {
        Setting::set('wa_portfolio_daily_limit', 'TIER_250'); // techo 250
        $phone = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 200]);
        $this->sentYesterday($phone, 200); // >= 100

        $this->artisan('wa:warmup-numbers')->assertSuccessful();

        $this->assertEquals(250, $phone->fresh()->daily_limit); // min(400, 250)
    }

    public function test_no_rampa_si_el_portfolio_es_desconocido(): void
    {
        // Sin Setting de portfolio: no se conoce el techo, no se rampa a ciegas.
        $phone = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 250]);
        $this->sentYesterday($phone, 250);

        $this->artisan('wa:warmup-numbers')->assertSuccessful();

        $this->assertEquals(250, $phone->fresh()->daily_limit);
    }

    public function test_no_sube_un_numero_pausado(): void
    {
        Setting::set('wa_portfolio_daily_limit', 'TIER_1K');
        $phone = PhoneNumber::factory()->create([
            'is_active'    => true,
            'daily_limit'  => 250,
            'paused_until' => now()->addHour(),
        ]);
        $this->sentYesterday($phone, 250);

        $this->artisan('wa:warmup-numbers')->assertSuccessful();

        $this->assertEquals(250, $phone->fresh()->daily_limit);
    }
}
