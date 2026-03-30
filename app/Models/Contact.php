<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'phone',
        'name',
        'status',
        'source',
        'notes',
        'opted_out_at',
        'snoozed_until',
    ];

    protected $casts = [
        'opted_out_at'  => 'datetime',
        'snoozed_until' => 'datetime',
    ];

    /**
     * Normaliza un número de teléfono al formato mexicano: 52XXXXXXXXXX (12 dígitos).
     * Retorna null si el número no es válido.
     */
    public static function normalizePhone(string $raw): ?string
    {
        // Quitar todo lo que no sea dígito
        $digits = preg_replace('/\D/', '', $raw);

        // 10 dígitos locales → agregar prefijo México
        if (strlen($digits) === 10) {
            $digits = '52' . $digits;
        }

        // 13 dígitos con formato 521XXXXXXXXXX (formato antiguo México) → normalizar a 12
        if (strlen($digits) === 13 && str_starts_with($digits, '521')) {
            $digits = '52' . substr($digits, 3);
        }

        // Validar: debe tener exactamente 12 dígitos y empezar con 52
        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            return $digits;
        }

        return null; // número inválido
    }

    /**
     * Marca el contacto como opt-out (irreversible por el cliente).
     */
    public function optOut(): void
    {
        $this->update([
            'status'       => 'opted_out',
            'opted_out_at' => now(),
        ]);
    }

    /**
     * Activa el snooze por N días (usa el cooldown_days de settings).
     */
    public function snooze(int $days): void
    {
        $this->update(['snoozed_until' => now()->addDays($days)]);
    }

    /**
     * True si el contacto tiene snooze activo (no se le debe enviar ahora).
     */
    public function isSnoozeActive(): bool
    {
        return $this->snoozed_until !== null && $this->snoozed_until->isFuture();
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
