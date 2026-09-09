<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Contacts\ContactSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Helper\TableSeparator;

/**
 * Da de alta los contactos nuevos que trae el API del cliente. Corre por scheduler una vez
 * al día y también a mano cuando haga falta.
 */
class SyncExternalContacts extends Command
{
    protected $signature = 'contactos:sincronizar {--dry-run : Muestra qué haría, sin escribir nada}';

    protected $description = 'Da de alta en el panel los contactos nuevos del API del cliente';

    public function handle(ContactSyncService $sync): int
    {
        $seco = (bool) $this->option('dry-run');

        if ($seco) {
            $this->warn('MODO SECO: no se va a escribir nada en la base de datos.');
        }

        $this->info('Consultando el API del cliente...');

        $r = $sync->sync($seco);

        if (! $r['ok']) {
            $this->error('No se pudo sincronizar: ' . $r['error']);
            $this->line('');
            $this->line('Revisa la conexión con: php artisan contactos:probar-api');

            if (! $seco) {
                Setting::set('contact_sync_last_run', json_encode([
                    'at'    => now()->toIso8601String(),
                    'ok'    => false,
                    'error' => $r['error'],
                ], JSON_UNESCAPED_UNICODE));
            }

            Log::error('Sincronización de contactos fallida', ['error' => $r['error']]);

            return self::FAILURE;
        }

        // El orden no es decorativo: los cuatro primeros renglones suman exactamente los
        // registros recibidos. Antes faltaba "repetidos" y la tabla no cuadraba - en la
        // primera corrida real se perdían 110 filas de 14,872 sin explicación.
        $this->newLine();
        $this->table(['Concepto', 'Cantidad'], [
            ['Registros recibidos del API',  $r['received']],
            ['  Con formato inválido',       $r['invalid']],
            ['  Repetidos en la respuesta',  $r['repeated']],
            ['  Excluidos por su estado',    $r['excluded']],
            ['  Teléfonos válidos',          $r['valid']],
            new TableSeparator(),
            ['Ya existían en el panel',      $r['duplicates']],
            [$seco ? 'Se DARÍAN de alta' : 'Dados de alta', $r['inserted']],
            [$seco ? 'Cambiarían de estado' : 'Estado actualizado', $r['refreshed']],
        ]);

        // Si esto no cuadra, algún renglón dejó de contarse y el reporte estaría mintiendo.
        $suma = $r['invalid'] + $r['repeated'] + $r['excluded'] + $r['valid'];

        if ($suma !== $r['received']) {
            $this->newLine();
            $this->warn("Aviso: los renglones suman {$suma} y se recibieron {$r['received']}. "
                . 'Hay filas sin clasificar - avisa al equipo técnico.');
        }

        // El desglose por estado es lo que permite decidir a quién sí ofrecerle: un
        // LIQUIDADO es prospecto de renovación, un BURÓ probablemente no.
        if ($r['by_status']) {
            $this->newLine();
            $this->line('Desglose por estado en el sistema del cliente:');
            $this->table(
                ['Estado', 'Registros'],
                collect($r['by_status'])->map(fn ($n, $e) => [$e, $n])->values()->all()
            );
        }

        // Las filas ilegibles casi siempre significan que el campo se llama distinto:
        // mostrarlas evita adivinar cuál es el nombre correcto para SYNC_FIELD_PHONE.
        if ($r['samples']) {
            $this->newLine();
            $this->warn('Ejemplos de filas cuyo teléfono no se pudo leer:');
            foreach ($r['samples'] as $fila) {
                $this->line('  ' . json_encode($fila, JSON_UNESCAPED_UNICODE));
            }
            $this->line('');
            $this->line('Si el teléfono sí viene ahí, ajusta SYNC_FIELD_PHONE en el .env.');
        }

        if (! $seco) {
            // El resumen se GUARDA, no solo se loguea. Cuando esto corre a las 4 AM nadie
            // ve la consola, y en producción el nivel de log puede estar en `warning`, con
            // lo que un Log::info no se escribe siquiera. Guardado en Setting sobrevive a
            // ambas cosas y se consulta con `contactos:ultima-corrida`.
            Setting::set('contact_sync_last_run', json_encode([
                'at'         => now()->toIso8601String(),
                'ok'         => true,
                'received'   => $r['received'],
                'invalid'    => $r['invalid'],
                'repeated'   => $r['repeated'],
                'excluded'   => $r['excluded'],
                'valid'      => $r['valid'],
                'duplicates' => $r['duplicates'],
                'inserted'   => $r['inserted'],
                'refreshed'  => $r['refreshed'],
                'by_status'  => $r['by_status'],
            ], JSON_UNESCAPED_UNICODE));

            Log::info('Contactos sincronizados desde el API', [
                'recibidos'  => $r['received'],
                'nuevos'     => $r['inserted'],
                'refrescados' => $r['refreshed'],
                'invalidos'  => $r['invalid'],
                'repetidos'  => $r['repeated'],
                'excluidos'  => $r['excluded'],
            ]);
        }

        return self::SUCCESS;
    }
}
