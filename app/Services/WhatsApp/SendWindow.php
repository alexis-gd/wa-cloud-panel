<?php

namespace App\Services\WhatsApp;

use App\Models\Setting;
use Carbon\Carbon;

/**
 * Ventana de envío de WhatsApp: L-V 9:00-22:00 America/Mexico_City.
 *
 * Fuente ÚNICA de verdad para "¿se puede enviar ahora?". La usan tanto el despacho
 * de campaña (CampaignController, puerta al ejecutar) como el job de envío
 * (SendWhatsAppMessage, guardia por mensaje). Tenerla en el job es lo que hace que
 * el horario se respete SIN importar cómo corra el worker: en producción el worker
 * corre 24/7 por Supervisor, así que la ventana del scheduler (Kernel) no basta —
 * una campaña grande podría cruzar las 22:00 con cola pendiente y seguir enviando.
 * El guardia del job cierra ese hueco.
 *
 * El "modo demo" (Setting schedule_bypass=1) la abre siempre, solo para pruebas.
 * En operación real se deja en 0 (ver checklist de producción).
 */
class SendWindow
{
    private const TZ         = 'America/Mexico_City';
    private const OPEN_HOUR  = 9;  // 9:00 abre
    private const CLOSE_HOUR = 22; // 22:00 cierra (hora >= 22 ya está fuera)

    /** ¿Estamos dentro de la ventana ahora? El modo demo la abre siempre. */
    public static function isOpen(?Carbon $now = null): bool
    {
        if (self::bypassActive()) {
            return true;
        }

        $now  = $now ? $now->copy()->setTimezone(self::TZ) : now(self::TZ);
        $hour = (int) $now->format('G');
        $dow  = (int) $now->format('N'); // 1=Lunes ... 7=Domingo

        return $dow <= 5 && $hour >= self::OPEN_HOUR && $hour < self::CLOSE_HOUR;
    }

    /**
     * Próxima apertura de la ventana: 9:00 del siguiente día hábil con hueco.
     * Usada para reencolar los jobs que caen fuera de horario.
     */
    public static function nextOpening(?Carbon $now = null): Carbon
    {
        $now       = $now ? $now->copy()->setTimezone(self::TZ) : now(self::TZ);
        $candidate = $now->copy()->setTime(self::OPEN_HOUR, 0);

        // Si la apertura de hoy ya pasó (o estamos dentro/después), saltar al día siguiente.
        if ($now->gte($candidate)) {
            $candidate->addDay()->setTime(self::OPEN_HOUR, 0);
        }

        // Saltar sábado y domingo.
        while ((int) $candidate->format('N') > 5) {
            $candidate->addDay();
        }

        return $candidate;
    }

    private static function bypassActive(): bool
    {
        return Setting::get('schedule_bypass', '0') === '1';
    }
}
