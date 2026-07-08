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
    protected $signature = 'db:clean-demo
        {--force : Ejecutar sin pedir confirmación}
        {--contacts : Borrar TAMBIÉN todos los contactos (y sus tags). Por default los conserva.}
        {--users : Borrar TAMBIÉN todos los usuarios. Úsalo junto con "db:seed --class=UserSeeder" para dejar solo los usuarios base.}';

    protected $description = 'Borra datos de prueba (conversaciones, respuestas SMS, campañas, logs, notificaciones, cola) sin tocar usuarios, números, plantillas ni configuración. Con --contacts borra contactos; con --users borra usuarios (para reseedearlos limpios).';

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
        $alsoContacts = (bool) $this->option('contacts');
        $alsoUsers    = (bool) $this->option('users');

        $extra = [];
        if ($alsoContacts) $extra[] = 'TODOS LOS CONTACTOS';
        if ($alsoUsers)    $extra[] = 'TODOS LOS USUARIOS (déjalos listos con "db:seed --class=UserSeeder")';

        $prompt = 'Esto borra conversaciones, respuestas SMS, campañas, logs y notificaciones'
            . ($extra ? ' Y ' . implode(' Y ', $extra) : '')
            . '. NO toca números, plantillas ni configuración. ¿Continuar?';

        if (! $this->option('force') && ! $this->confirm($prompt)) {
            $this->info('Cancelado.');
            return self::SUCCESS;
        }

        $tables = self::TABLES;

        // Los opcionales van al final: sus FK (conversaciones, asignaciones) ya se borraron arriba.
        if ($alsoContacts) {
            $tables = array_merge($tables, ['contact_tag', 'contacts']);
        }
        if ($alsoUsers) {
            // Limpia también los tokens Sanctum para no dejar sesiones huérfanas.
            $tables = array_merge($tables, ['personal_access_tokens', 'users']);
        }

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $deleted = DB::table($table)->delete();
            $this->line("  {$table}: {$deleted} borrados");
        }

        $conserva = ['números', 'plantillas', 'configuración'];
        if (! $alsoContacts) array_unshift($conserva, 'contactos');
        if (! $alsoUsers)    array_unshift($conserva, 'usuarios');
        $this->info('Datos de prueba limpiados. Intactos: ' . implode(', ', $conserva) . '.');

        if ($alsoUsers) {
            $this->warn('Borraste los usuarios. Corre YA: php artisan db:seed --class=UserSeeder');
        }

        return self::SUCCESS;
    }
}
