<?php

namespace App\Console\Commands;

use App\Services\Contacts\ContactSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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

            Log::error('Sincronización de contactos fallida', ['error' => $r['error']]);

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Concepto', 'Cantidad'], [
            ['Registros recibidos del API', $r['received']],
            ['Teléfonos válidos',           $r['valid']],
            ['Con formato inválido',        $r['invalid']],
            ['Excluidos por su estado',     $r['excluded']],
            ['Ya existían en el panel',     $r['duplicates']],
            [$seco ? 'Se DARÍAN de alta' : 'Dados de alta', $r['inserted']],
            [$seco ? 'Cambiarían de estado' : 'Estado actualizado', $r['refreshed']],
        ]);

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
            Log::info('Contactos sincronizados desde el API', [
                'recibidos' => $r['received'],
                'nuevos'    => $r['inserted'],
            ]);
        }

        return self::SUCCESS;
    }
}
