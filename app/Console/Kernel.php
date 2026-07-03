<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Procesar queue de mensajes WhatsApp — solo L-V, 9AM-10PM hora México (CST/UTC-6)
        // Cubre todo México: Baja California (PST) recibe desde 7AM, Veracruz (CST) hasta 10PM.
        // Sin override manual — el scheduler es la única fuente de verdad.
        $schedule->command('queue:work --stop-when-empty --tries=3')
            ->everyMinute()
            ->timezone('America/Mexico_City')
            ->weekdays()
            ->between('9:00', '22:00')
            ->withoutOverlapping()
            ->runInBackground();

        // Limpiar tokens Sanctum expirados de la BD (los marca expirados en memoria,
        // pero no los borra automáticamente sin este comando).
        $schedule->command('sanctum:prune-expired --hours=8')->daily();

        // Marcar contactos inalcanzables a las 6AM CST, antes de que abra la ventana
        // de envíos (9AM). La query es pesada (agregados sobre message_log) y aquí
        // corre con la BD ociosa, sin competir con las campañas.
        $schedule->command('wa:mark-unreachable')
            ->dailyAt('06:00')
            ->timezone('America/Mexico_City')
            ->withoutOverlapping();

        // Vigila que el webhook SMS siga devolviendo eventos; alerta en la campana si no.
        $schedule->command('sms:monitor-webhook')
            ->everyFifteenMinutes()
            ->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
