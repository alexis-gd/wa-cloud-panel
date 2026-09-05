<?php

namespace App\Services\System;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * El latido del programador de tareas.
 *
 * Por qué existe: una sola línea de cron (`* * * * * php artisan schedule:run`) mueve TODO lo
 * automático del sistema - el alta de contactos del API del cliente, el warm-up de los
 * números, el marcado de inalcanzables y las reconciliaciones de SMS. Si esa línea se rompe
 * (un crontab mal editado, permisos de `www-data`, una actualización de PHP que cambia la
 * ruta del binario), nada avisa: el panel se ve perfectamente normal mientras el sistema se
 * desafina en silencio, y el cliente lo nota días después.
 *
 * Un cron muerto no puede avisar que está muerto, así que la detección va al revés: el
 * scheduler deja una marca de tiempo cada minuto y es el PANEL quien nota que está fría.
 *
 * Ojo con lo que NO se detiene: el worker de la cola corre bajo Supervisor, aparte del cron,
 * así que las campañas se siguen enviando. Es una degradación, no un apagón - y el aviso al
 * operador tiene que decirlo así para que no crea que todo se paró.
 */
class SchedulerHeartbeat
{
    private const KEY = 'scheduler_last_run';

    /**
     * Minutos sin latido a partir de los cuales se considera caído.
     *
     * El scheduler late cada minuto, así que 15 es inequívoco: ni el servidor más ocupado se
     * salta quince minutos seguidos. Margen de sobra para no dar falsas alarmas.
     */
    public const STALE_MINUTES = 15;

    /** La deja el propio scheduler, cada minuto. */
    public static function latir(): void
    {
        Setting::set(self::KEY, now()->toIso8601String());
    }

    /** Cuándo latió por última vez. Null = nunca (recién desplegado o cron que nunca corrió). */
    public static function ultimoLatido(): ?Carbon
    {
        $valor = Setting::get(self::KEY);

        if (empty($valor)) {
            return null;
        }

        try {
            return Carbon::parse($valor);
        } catch (\Throwable) {
            // Un valor corrupto se trata como "sin latido": es más seguro avisar de más.
            return null;
        }
    }

    /**
     * Estado para el panel.
     *
     * @return array{healthy: bool, minutes_stale: ?int, last_run: ?string, never_ran: bool}
     */
    public static function estado(): array
    {
        $ultimo = self::ultimoLatido();

        if ($ultimo === null) {
            // Nunca latió. Puede ser un deploy recién hecho (el cron tarda hasta un minuto
            // en dejar la primera marca) o un cron que no existe. No se puede distinguir,
            // así que se reporta como sano: avisar en cada deploy sería ruido y el operador
            // dejaría de hacerle caso a la barra.
            return [
                'healthy'       => true,
                'minutes_stale' => null,
                'last_run'      => null,
                'never_ran'     => true,
            ];
        }

        $minutos = (int) $ultimo->diffInMinutes(now());

        return [
            'healthy'       => $minutos < self::STALE_MINUTES,
            'minutes_stale' => $minutos,
            'last_run'      => $ultimo->setTimezone('America/Mexico_City')->format('Y-m-d H:i'),
            'never_ran'     => false,
        ];
    }
}
