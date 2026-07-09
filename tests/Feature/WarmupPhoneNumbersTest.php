<?php

namespace Tests\Feature;

use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WarmupPhoneNumbersTest extends TestCase
{
    use RefreshDatabase;

    /** Calidad que Meta reportará en el test actual. Default GREEN (número sano). */
    protected string $qualityRating = 'GREEN';

    protected function setUp(): void
    {
        parent::setUp();
        // Un solo stub que lee la propiedad al momento de la request, así fakeQuality()
        // cambia la respuesta sin apilar stubs (donde el primero gana y opaca al resto).
        Http::fake(['*' => fn () => Http::response(['quality_rating' => $this->qualityRating], 200)]);
    }

    /** Ajusta el quality_rating que Meta devolverá para este test. */
    private function fakeQuality(string $rating): void
    {
        $this->qualityRating = $rating;
    }

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

    // ── Warm-down por calidad suave (quality_rating de Meta) ──────────────────────

    public function test_calidad_red_recula_el_limite(): void
    {
        Setting::set('wa_portfolio_daily_limit', 'TIER_1K');
        $this->fakeQuality('RED');
        $phone = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 1000]);
        $this->sentYesterday($phone, 900); // uso alto, pero RED manda: recula

        $this->artisan('wa:warmup-numbers')->assertSuccessful();

        $this->assertEquals(500, $phone->fresh()->daily_limit); // 1000 / 2
    }

    public function test_calidad_red_recula_aunque_no_haya_enviado(): void
    {
        Setting::set('wa_portfolio_daily_limit', 'TIER_1K');
        $this->fakeQuality('RED');
        $phone = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 800]);
        // Sin envíos ayer: el warm-down por calidad no depende del uso.

        $this->artisan('wa:warmup-numbers')->assertSuccessful();

        $this->assertEquals(400, $phone->fresh()->daily_limit);
    }

    public function test_calidad_red_no_baja_del_piso(): void
    {
        Setting::set('wa_portfolio_daily_limit', 'TIER_1K');
        $this->fakeQuality('RED');
        $phone = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 250]);

        $this->artisan('wa:warmup-numbers')->assertSuccessful();

        $this->assertEquals(250, $phone->fresh()->daily_limit); // piso WARMUP_FLOOR
    }

    public function test_calidad_yellow_ni_sube_ni_baja(): void
    {
        Setting::set('wa_portfolio_daily_limit', 'TIER_1K');
        $this->fakeQuality('YELLOW');
        $phone = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 250]);
        $this->sentYesterday($phone, 250); // aunque calificaría para subir, YELLOW lo frena

        $this->artisan('wa:warmup-numbers')->assertSuccessful();

        $this->assertEquals(250, $phone->fresh()->daily_limit);
    }

    public function test_calidad_desconocida_permite_warm_up_normal(): void
    {
        Setting::set('wa_portfolio_daily_limit', 'TIER_1K');
        $this->fakeQuality('UNKNOWN');
        $phone = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 250]);
        $this->sentYesterday($phone, 200);

        $this->artisan('wa:warmup-numbers')->assertSuccessful();

        $this->assertEquals(500, $phone->fresh()->daily_limit); // UNKNOWN no bloquea el warm-up
    }
}
