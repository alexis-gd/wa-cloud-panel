<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'phone',
        'name',
        'status',
        'source',
        'notes',
        'opted_out_at',
        'opted_out_source',
        'snoozed_until',
        'sms_opt_out',
        'sms_blocked',
        'sms_invalid',
        'sms_bounce_count',
    ];

    protected $casts = [
        'opted_out_at'  => 'datetime',
        'snoozed_until' => 'datetime',
        'sms_opt_out'   => 'boolean',
        'sms_blocked'   => 'boolean',
        'sms_invalid'   => 'boolean',
    ];

    /**
     * Marca opt-out de SMS (irreversible por el cliente). Independiente del opt-out WA:
     * el contacto puede seguir activo para WhatsApp aunque pida baja de SMS y viceversa.
     */
    public function smsOptOut(): void
    {
        $this->update(['sms_opt_out' => true]);
    }

    /**
     * True si el contacto NO puede recibir SMS (opt-out, bloqueado o número inválido).
     */
    public function isSmsBlocked(): bool
    {
        return $this->sms_opt_out || $this->sms_blocked || $this->sms_invalid;
    }

    /**
     * Registra un rebote SMS. A los 3 consecutivos se auto-bloquea el canal SMS
     * (regla contexto-sms). No afecta el canal WhatsApp.
     */
    public function registerSmsBounce(): void
    {
        $count = $this->sms_bounce_count + 1;
        $this->update([
            'sms_bounce_count' => $count,
            'sms_blocked'      => $count >= 3 ? true : $this->sms_blocked,
        ]);
    }

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
    public function optOut(string $source = 'manual'): void
    {
        $this->update([
            'status'            => 'opted_out',
            'opted_out_at'      => now(),
            'opted_out_source'  => $source,
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

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ConversationAssignment::class);
    }

    public function currentAssignment(): ?ConversationAssignment
    {
        return $this->assignments()->latest('assigned_at')->first();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
