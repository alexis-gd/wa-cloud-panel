<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        // Solo crea si no existe — seguro correr múltiples veces
        User::firstOrCreate(
            ['email' => 'admin@prestamas.mx'],
            [
                'name'      => 'Administrador',
                'password'  => Hash::make('admin1234'),
                'role'      => 'admin',
                'is_active' => true,
            ]
        );
    }
}
