<?php

namespace App\Services\WhatsApp;

use App\Models\Setting;

/**
 * Límite de mensajería del Business Portfolio (Meta). Es el techo de TODA la cuenta por día,
 * compartido por todos los números. Lo llena `SettingsController::phoneHealth()` leyendo el
 * campo `whatsapp_business_manager_messaging_limit` de Meta (el viejo `messaging_limit_tier`
 * está deprecado). Formatos: "TIER_250", "TIER_10K", "100000", "UNLIMITED".
 */
class PortfolioLimit
{
    /** Tope de seguridad por día cuando Meta reporta el portfolio como ilimitado. */
    public const UNLIMITED_CAP = 100000;

    private const KEY = 'wa_portfolio_daily_limit';

    private const KEY_UPDATED_AT = 'wa_portfolio_limit_updated_at';

    public static function raw(): ?string
    {
        return Setting::get(self::KEY);
    }

    /**
     * Guarda el límite tal como lo reporta Meta. Punto único de escritura: lo usan tanto
     * `phoneHealth()` (cuando alguien abre el semáforo) como `wa:warmup-numbers` (a diario),
     * así el tier se refresca aunque nadie entre al panel. Ignora valores vacíos para no
     * borrar el último bueno si Meta no manda el campo en alguna respuesta.
     */
    public static function remember(?string $raw): void
    {
        if ($raw === null || $raw === '') {
            return;
        }

        Setting::set(self::KEY, $raw);
        Setting::set(self::KEY_UPDATED_AT, now()->toIso8601String());
    }

    /** True si Meta ya reportó un límite (aunque sea "UNLIMITED"). */
    public static function isKnown(): bool
    {
        return (bool) self::raw();
    }

    public static function isUnlimited(): bool
    {
        $raw = self::raw();

        return $raw !== null && str_contains(strtoupper($raw), 'UNLIMITED');
    }

    /**
     * Techo diario como entero. null si Meta aún no lo reportó. Si es ilimitado devuelve
     * UNLIMITED_CAP (para no crecer al infinito en el warm-up).
     */
    public static function daily(): ?int
    {
        $raw = self::raw();
        if (! $raw) {
            return null;
        }

        if (self::isUnlimited()) {
            return self::UNLIMITED_CAP;
        }

        $s = str_replace('TIER_', '', strtoupper(trim($raw)));
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([KM]?)$/', $s, $m)) {
            $mult = $m[2] === 'K' ? 1000 : ($m[2] === 'M' ? 1000000 : 1);

            return (int) round((float) $m[1] * $mult);
        }

        return null;
    }
}
