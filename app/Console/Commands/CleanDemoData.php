<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Borra los datos transaccionales de prueba dejando el sistema listo para operar:
 * conversaciones, respuestas SMS, campañas, logs, notificaciones y la cola.
 *
 * NO toca: usuarios, números de envío, plantillas, contactos ni configuración
 * (feature flags, cooldown, etc.). Para un reset TOTAL usar `migrate:fresh --seed`.
 */
class CleanDemoData extends Command
{
    protected $signature = 'db:clean-demo {--force : Ejecutar sin pedir confirmación}';

    protected $description = 'Borra datos de prueba (conversaciones, respuestas SMS, campañas, logs, notificaciones, cola) sin tocar usuarios, números, plantillas, contactos ni configuración';

    // Orden importa: primero los hijos (FK), luego las campañas.
    private const TABLES = [
        'message_log',
        'conversations',
        'sms_inbound_messages',
        'conversation_assignments',
        'app_notifications',
        'campaigns',
        'jobs',
        'failed_jobs',
    ];

    public function handle(): int
    {
        if (! $this->option('force')
            && ! $this->confirm('Esto borra conversaciones, respuestas SMS, campañas, logs y notificaciones. NO toca usuarios, números, plantillas, contactos ni configuración. ¿Continuar?')) {
            $this->info('Cancelado.');
            return self::SUCCESS;
        }

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $deleted = DB::table($table)->delete();
            $this->line("  {$table}: {$deleted} borrados");
        }

        $this->info('Datos de prueba limpiados. Usuarios, números, plantillas, contactos y configuración intactos.');

        return self::SUCCESS;
    }
}
