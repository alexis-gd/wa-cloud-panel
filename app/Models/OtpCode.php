<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * Un codigo de verificacion pedido por la app del cliente.
 *
 * El codigo en claro solo existe dos veces: en el momento de generarlo (para mandarlo a
 * WhatsApp) y en la cabeza del usuario. Aqui se guarda su hash, igual que una contrasena.
 */
class OtpCode extends Model
{
    use HasFactory;

    /** Lo que el pie de la plantilla promete. Nuestro API lo tiene que cumplir de verdad. */
    public const MINUTOS_DE_VIDA = 10;

    /** Intentos de verificacion antes de quemar el codigo. */
    public const MAX_INTENTOS = 5;

    protected $fillable = [
        'phone', 'code_hash', 'reference', 'status', 'attempts',
        'expires_at', 'sent_at', 'verified_at',
        'message_id', 'error_code', 'error_message',
    ];

    protected $casts = [
        'attempts'    => 'integer',
        'expires_at'  => 'datetime',
        'sent_at'     => 'datetime',
        'verified_at' => 'datetime',
    ];

    public function estaVigente(): bool
    {
        return in_array($this->status, ['pending', 'sent'], true)
            && $this->expires_at->isFuture()
            && $this->attempts < self::MAX_INTENTOS;
    }

    public function coincide(string $codigo): bool
    {
        return Hash::check($codigo, $this->code_hash);
    }

    /**
     * Marca que el mensaje salio hacia Meta.
     *
     * El reloj de caducidad arranca aqui, no al generarlo: si la cola tardo dos minutos en
     * despachar, el usuario no tiene por que perderlos. Lo que ve en el mensaje ("caduca en 10
     * minutos") empieza a correr cuando el mensaje existe.
     */
    public function marcarEnviado(string $messageId): void
    {
        $this->update([
            'status'     => 'sent',
            'message_id' => $messageId,
            'sent_at'    => now(),
            'expires_at' => now()->addMinutes(self::MINUTOS_DE_VIDA),
        ]);
    }

    public function marcarFallido(?string $codigo, ?string $mensaje): void
    {
        $this->update([
            'status'        => 'failed',
            'error_code'    => $codigo,
            'error_message' => $mensaje ? substr($mensaje, 0, 500) : null,
        ]);
    }

    /** Cuantos OTP salieron hoy. Lo suma el freno del portfolio: Meta no distingue categorias. */
    public static function enviadosHoy(): int
    {
        return static::whereBetween('sent_at', [
            now('America/Mexico_City')->startOfDay()->utc(),
            now('America/Mexico_City')->endOfDay()->utc(),
        ])->whereIn('status', ['sent', 'verified', 'expired'])->count();
    }
}
