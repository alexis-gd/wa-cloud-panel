<?php

namespace App\Console\Commands;

use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Services\WhatsApp\PortfolioLimit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Warm-up automático: sube gradualmente el `daily_limit` de cada número hasta el techo del
 * portfolio, imitando el criterio de Meta (subir a quien usó >=50% de su límite ayer y no
 * está pausado). Nunca crece por encima del límite que Meta reporta, así que no expone la
 * cuenta. Corre a diario por el scheduler.
 */
class WarmupPhoneNumbersCommand extends Command
{
    protected $signature   = 'wa:warmup-numbers';
    protected $description  = 'Sube gradualmente el daily_limit de los números activos (warm-up) hasta el límite del portfolio';

    /** Fracción del límite que un número debe haber usado ayer para merecer subir (criterio Meta). */
    private const USAGE_THRESHOLD = 0.5;

    public function handle(): int
    {
        // Sin límite de portfolio conocido no rampamos: crecer a ciegas podría rebasar a Meta.
        // En cuanto phoneHealth reporte el límite (producción), el warm-up arranca solo.
        if (! PortfolioLimit::isKnown()) {
            $this->info('Límite del portfolio aún desconocido (Meta no lo ha reportado). Sin warm-up.');

            return self::SUCCESS;
        }

        $ceiling = PortfolioLimit::daily(); // entero (ilimitado -> UNLIMITED_CAP)

        $tz     = 'America/Mexico_City';
        $yStart = now($tz)->subDay()->startOfDay()->utc();
        $yEnd   = now($tz)->subDay()->endOfDay()->utc();

        $numbers = PhoneNumber::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('paused_until')->orWhere('paused_until', '<', now()))
            ->get();

        $raised = 0;

        foreach ($numbers as $number) {
            if ($number->daily_limit >= $ceiling) {
                continue; // ya en el techo del portfolio
            }

            $sentYesterday = MessageLog::where('phone_number_id', $number->id)
                ->whereBetween('sent_at', [$yStart, $yEnd])
                ->whereIn('status', ['sent', 'delivered', 'read'])
                ->count();

            // Solo sube a quien realmente está enviando (>=50% de su límite), como Meta.
            if ($sentYesterday < $number->daily_limit * self::USAGE_THRESHOLD) {
                continue;
            }

            $newLimit = min($number->daily_limit * 2, $ceiling);
            if ($newLimit > $number->daily_limit) {
                $number->update(['daily_limit' => $newLimit]);
                $raised++;
            }
        }

        $this->info("Warm-up: {$raised} número(s) subieron de límite (techo del portfolio: {$ceiling}).");
        Log::info('wa:warmup-numbers ejecutado', ['raised' => $raised, 'ceiling' => $ceiling]);

        return self::SUCCESS;
    }
}
