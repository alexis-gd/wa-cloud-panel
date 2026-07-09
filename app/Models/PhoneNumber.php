<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhoneNumber extends Model
{
    use HasFactory;

    protected $fillable = [
        'display_name',
        'phone_number_id',
        'waba_id',
        'token',
        'is_active',
        'daily_limit',
        'paused_until',
    ];

    protected $casts = [
        'token'        => 'encrypted', // AES-256 usando APP_KEY del .env
        'is_active'    => 'boolean',
        'paused_until' => 'datetime',
    ];

    /** Indica si el circuit breaker tiene el número pausado ahora mismo. */
    public function isPaused(): bool
    {
        return $this->paused_until !== null && $this->paused_until->isFuture();
    }

    /** Piso del warm-up: ningún número baja de aquí. */
    public const WARMUP_FLOOR = 250;

    /** Pausa el número por $minutes minutos (circuit breaker). */
    public function pauseFor(int $minutes): void
    {
        $this->update(['paused_until' => now()->addMinutes($minutes)]);
        // Semáforo del Dashboard en vivo: avisa que el número quedó pausado.
        event(\App\Events\PhoneNumberPaused::fromModel($this));
    }

    /**
     * Warm-down: baja el daily_limit a la mitad (piso WARMUP_FLOOR) cuando el número tuvo un
     * problema de calidad/spam. Es la contraparte del warm-up: si sube por buen uso, baja al
     * primer error para recular y re-calentar conservador tras la pausa.
     */
    public function backOffDailyLimit(): void
    {
        $reduced = max(self::WARMUP_FLOOR, intdiv($this->daily_limit, 2));
        if ($reduced < $this->daily_limit) {
            $this->update(['daily_limit' => $reduced]);
        }
    }
}
