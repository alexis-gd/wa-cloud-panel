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
        'phone', 'code_hash', 'reference', 'status', 'delivery_status', 'attempts',
        'expires_at', 'sent_at', 'delivered_at', 'verified_at',
        'message_id', 'error_code', 'error_message',
    ];

    protected $casts = [
        'attempts'    => 'integer',
        'expires_at'   => 'datetime',
        'sent_at'      => 'datetime',
        'delivered_at' => 'datetime',
        'verified_at'  => 'datetime',
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

    /**
     * Lo que WhatsApp contesta sobre el mensaje: llego, lo leyo, o rebotó.
     *
     * Va aparte de `status` porque son dos preguntas distintas. Un codigo **entregado y nunca
     * usado** es normal (la persona se distrajo); uno que **nunca llego** es un problema. Sin
     * esta columna los dos se veian igual en el panel: "Enviado", para siempre.
     *
     * Si el mensaje rebotó, el codigo muere con el: nadie lo va a poder escribir.
     */
    public function registrarEntrega(string $estado, ?string $codigoError = null, ?string $mensaje = null): void
    {
        $cambios = ['delivery_status' => $estado];

        if ($estado === 'delivered' && ! $this->delivered_at) {
            $cambios['delivered_at'] = now();
        }

        if ($estado === 'failed') {
            $cambios['status']        = 'failed';
            $cambios['error_code']    = $codigoError;
            $cambios['error_message'] = $mensaje ? substr($mensaje, 0, 500) : null;
        }

        $this->update($cambios);
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
