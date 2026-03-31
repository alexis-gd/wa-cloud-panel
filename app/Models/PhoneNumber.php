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

    /** Pausa el número por $minutes minutos (circuit breaker). */
    public function pauseFor(int $minutes): void
    {
        $this->update(['paused_until' => now()->addMinutes($minutes)]);
    }
}
