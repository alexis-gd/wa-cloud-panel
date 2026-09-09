<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Qué pasó en la última sincronización de contactos.
 *
 * Existe porque el cron corre a las 4 AM y nadie ve esa consola. El resumen se guarda en un
 * Setting (no solo en el log, que en producción puede estar en nivel `warning` y ni siquiera
 * escribirse), y esto lo muestra igual que si se hubiera visto correr.
 */
class ShowLastContactSync extends Command
{
    protected $signature = 'contactos:ultima-corrida {--cartera : Desglose actual de contactos por estado de cartera}';

    protected $description = 'Muestra qué pasó en la última sincronización de contactos del API del cliente';

    public function handle(): int
    {
        if ($this->option('cartera')) {
            return $this->desgloseCartera();
        }

        $crudo = Setting::get('contact_sync_last_run');

        if (empty($crudo)) {
            $this->warn('Todavía no hay ninguna corrida registrada.');
            $this->line('');
            $this->line('Puede ser que el cron nunca haya corrido, o que la última vez que');
            $this->line('corrió fuera antes de que existiera este registro.');
            $this->line('');
            $this->line('Para verificar el cron:  sudo crontab -l -u www-data | grep schedule:run');
            $this->line('Para correrlo a mano:    php artisan contactos:sincronizar --dry-run');

            return self::SUCCESS;
        }

        $r = json_decode($crudo, true);

        if (! is_array($r)) {
            $this->error('El registro de la última corrida está corrupto.');
            return self::FAILURE;
        }

        $cuando = Carbon::parse($r['at'])->setTimezone('America/Mexico_City');

        $this->newLine();
        $this->line('Última corrida: <options=bold>' . $cuando->format('Y-m-d H:i') . '</> (hora de México)');
        $this->line('Hace ' . $cuando->diffForHumans(null, true) . '.');
        $this->newLine();

        // ── Si falló, lo importante es el motivo, no la tabla ──
        if (! ($r['ok'] ?? false)) {
            $this->error('LA ÚLTIMA CORRIDA FALLÓ');
            $this->line('Motivo: ' . ($r['error'] ?? 'sin detalle'));
            $this->newLine();
            $this->line('Diagnostica la conexión con: php artisan contactos:probar-api');

            return self::SUCCESS;
        }

        $this->table(['Concepto', 'Cantidad'], [
            ['Registros recibidos del API',  $r['received']],
            ['  Con formato inválido',       $r['invalid']],
            ['  Repetidos en la respuesta',  $r['repeated']],
            ['  Excluidos por su estado',    $r['excluded']],
            ['  Teléfonos válidos',          $r['valid']],
            ['Ya existían en el panel',      $r['duplicates']],
            ['Dados de alta',                $r['inserted']],
            ['Estado de cartera actualizado', $r['refreshed']],
        ]);

        if (! empty($r['by_status'])) {
            $this->newLine();
            $this->line('Desglose por estado en el sistema del cliente:');
            $this->table(
                ['Estado', 'Registros'],
                collect($r['by_status'])->map(fn ($n, $e) => [$e, $n])->values()->all()
            );
        }

        // ── Contraste con la foto de HOY en la base ──
        // El resumen dice qué hizo esa corrida; esto dice cómo quedó la base. Si alguien
        // borró contactos a mano después, los números no cuadran y hay que saberlo.
        $this->newLine();
        $this->line('Estado actual de la base (no de esa corrida):');
        $this->table(['Concepto', 'Cantidad'], [
            ['Contactos totales',               Contact::count()],
            ['Dados de alta por el API',        Contact::where('source', 'api')->count()],
            ['Con estado de cartera',           Contact::whereNotNull('portfolio_status')->count()],
        ]);

        $this->newLine();
        $this->line('Para ver el desglose por cartera:  php artisan contactos:ultima-corrida --cartera');

        return self::SUCCESS;
    }

    /**
     * Cómo está repartida la base por estado de cartera HOY.
     *
     * Es la foto actual, no la de una corrida: sirve para saber a cuántos se les podría
     * mandar una campaña de renovación antes de armarla.
     */
    private function desgloseCartera(): int
    {
        $filas = Contact::selectRaw('portfolio_status, COUNT(*) total')
            ->groupBy('portfolio_status')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($f) => [$f->portfolio_status ?: '(sin estado)', $f->total])
            ->all();

        if ($filas === []) {
            $this->warn('Ningún contacto tiene estado de cartera todavía.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Contactos por estado de cartera (foto de ahora):');
        $this->table(['Cartera', 'Contactos'], $filas);

        // Los "(sin estado)" son los que entraron por Excel o a mano, no por el API.
        $this->newLine();
        $this->line('Los "(sin estado)" no vinieron del API: se subieron por Excel o se');
        $this->line('dieron de alta a mano, así que el cliente no los tiene en su cartera.');

        return self::SUCCESS;
    }
}
