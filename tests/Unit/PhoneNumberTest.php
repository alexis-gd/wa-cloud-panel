<?php

namespace Tests\Unit;

use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    use RefreshDatabase;

    // ── isPaused() ────────────────────────────────────────────────────────────

    public function test_is_paused_false_cuando_paused_until_es_null(): void
    {
        $phone = PhoneNumber::factory()->create(['paused_until' => null]);

        $this->assertFalse($phone->isPaused());
    }

    public function test_is_paused_false_cuando_paused_until_es_pasado(): void
    {
        $phone = PhoneNumber::factory()->create([
            'paused_until' => now()->subMinutes(5),
        ]);

        $this->assertFalse($phone->isPaused());
    }

    public function test_is_paused_true_cuando_paused_until_es_futuro(): void
    {
        $phone = PhoneNumber::factory()->create([
            'paused_until' => now()->addHour(),
        ]);

        $this->assertTrue($phone->isPaused());
    }

    // ── pauseFor() ────────────────────────────────────────────────────────────

    public function test_pause_for_escribe_paused_until_en_futuro(): void
    {
        $phone = PhoneNumber::factory()->create(['paused_until' => null]);

        $phone->pauseFor(60);

        $phone->refresh();
        $this->assertTrue($phone->isPaused());
        $this->assertEqualsWithDelta(60, now()->diffInMinutes($phone->paused_until), 1);
    }

    public function test_pause_for_sobreescribe_pausa_anterior(): void
    {
        $phone = PhoneNumber::factory()->create([
            'paused_until' => now()->addMinutes(10),
        ]);

        $phone->pauseFor(1440); // 24 horas

        $phone->refresh();
        $this->assertEqualsWithDelta(1440, now()->diffInMinutes($phone->paused_until), 2);
    }
}
