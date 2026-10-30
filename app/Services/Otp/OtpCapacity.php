<?php

namespace App\Services\Otp;

use App\Models\MessageLog;
use App\Models\OtpCode;
use App\Services\WhatsApp\PortfolioLimit;

/**
 * Cuanto cupo del dia queda, y cuanto de ese cupo es solo para los OTP.
 *
 * Meta pone un techo diario **por cuenta** y **no distingue categorias**: un codigo de
 * verificacion gasta el mismo cupo que un mensaje de publicidad. Por eso el numero dedicado de
 * WhatsApp no le da capacidad propia al OTP - comparte el mismo techo - y por eso hace falta
 * apartarle una parte aqui.
 *
 * El trato: las campanas frenan antes, el OTP llega hasta el tope real.
 */
class OtpCapacity
{
    /**
     * Lo que ya se gasto hoy del techo de Meta.
     *
     * Suma las dos tablas porque los OTP viven aparte de `message_log` (ahi adentro
     * envenenarian el dedup y el cooldown de campanas), pero para Meta cuentan igual.
     */
    public static function usadoHoy(): int
    {
        $inicio = now('America/Mexico_City')->startOfDay()->utc();
        $fin    = now('America/Mexico_City')->endOfDay()->utc();

        $campanas = MessageLog::whereBetween('sent_at', [$inicio, $fin])
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->count();

        return $campanas + OtpCode::enviadosHoy();
    }

    /** El techo real de Meta. null si Meta todavia no lo reporta (entonces no se frena nada). */
    public static function techoReal(): ?int
    {
        return PortfolioLimit::daily();
    }

    /**
     * El techo para las campanas: el real menos la reserva del OTP.
     *
     * Con 0% de reserva devuelve el techo real, que es como se comportaba antes de esto.
     */
    public static function techoCampanas(): ?int
    {
        $real = self::techoReal();

        if ($real === null) {
            return null;
        }

        $reserva = max(0, min(100, (int) config('otp.reserve_percent')));

        return (int) floor($real * (100 - $reserva) / 100);
    }

    /** Cuantos OTP caben todavia hoy. */
    public static function quedanParaOtp(): ?int
    {
        $real = self::techoReal();

        return $real === null ? null : max(0, $real - self::usadoHoy());
    }
}
