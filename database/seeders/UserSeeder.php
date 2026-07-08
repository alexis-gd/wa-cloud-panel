<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Usuarios base del sistema (uno por rol). Idempotente (updateOrCreate por email):
 * seguro correr en cada `migrate:fresh --seed`. Dominio oficial: prestamaz.mx (con Z).
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'Super Admin',   'email' => 'superadmin@prestamaz.mx', 'role' => 'superadmin', 'password' => 'superadmin1234'],
            ['name' => 'Administrador', 'email' => 'admin@prestamaz.mx',      'role' => 'admin',      'password' => 'admin1234'],
            ['name' => 'Operador',      'email' => 'operador@prestamaz.mx',   'role' => 'operator',   'password' => 'operador1234'],
            ['name' => 'Agente 1',      'email' => 'agente1@prestamaz.mx',    'role' => 'agent',      'password' => 'agente1234'],
            ['name' => 'Agente 2',      'email' => 'agente2@prestamaz.mx',    'role' => 'agent',      'password' => 'agente1234'],
        ];

        foreach ($users as $u) {
            User::updateOrCreate(
                ['email' => $u['email']],
                [
                    'name'      => $u['name'],
                    'role'      => $u['role'],
                    'is_active' => true,
                    'password'  => Hash::make($u['password']),
                ],
            );
        }
    }
}
