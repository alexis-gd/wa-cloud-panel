<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Deja la BD limpia y usable tras `migrate:fresh --seed`:
 *  - Usuarios: superadmin, admin, operador y 2 agentes (UserSeeder).
 *  - Número de envío desde .env (PhoneNumberSeeder, solo si hay credenciales).
 *  - Contactos base de prueba (ContactSeeder).
 *
 * NO seedea: campañas, message_log, conversaciones, respuestas SMS ni plantillas
 * (arrancan vacías a propósito). Las plantillas WhatsApp se re-sincronizan desde Meta
 * y las SMS se crean en el panel.
 *
 * Para borrar solo datos transaccionales (conversaciones, respuestas SMS, campañas,
 * logs) sin tocar usuarios/números/plantillas/config, usar `php artisan db:clean-demo`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            PhoneNumberSeeder::class,
            ContactSeeder::class,
        ]);
    }
}
