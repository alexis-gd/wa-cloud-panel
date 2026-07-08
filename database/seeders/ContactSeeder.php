<?php

namespace Database\Seeders;

use App\Models\Contact;
use Illuminate\Database\Seeder;

/**
 * Contactos base de prueba (números reales de pruebas del equipo). Idempotente
 * (updateOrCreate por teléfono). Los teléfonos van en formato México 12 dígitos (52 + 10).
 */
class ContactSeeder extends Seeder
{
    public function run(): void
    {
        $contacts = [
            ['phone' => '529231122058', 'name' => 'Alexis Garcia 2'],
            ['phone' => '526691498479', 'name' => 'Heriberto'],
            ['phone' => '526691273636', 'name' => 'Joseph Bustamante'],
            ['phone' => '529231311146', 'name' => 'Juan Pérez'],
        ];

        foreach ($contacts as $c) {
            Contact::updateOrCreate(
                ['phone' => $c['phone']],
                ['name' => $c['name'], 'status' => 'active', 'source' => 'seed'],
            );
        }
    }
}
