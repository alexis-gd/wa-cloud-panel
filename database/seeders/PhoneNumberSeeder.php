<?php

namespace Database\Seeders;

use App\Models\PhoneNumber;
use Illuminate\Database\Seeder;

/**
 * Número de envío WhatsApp desde .env. Idempotente (firstOrCreate por phone_number_id).
 * Si no hay credenciales en .env (WA_PHONE_ID vacío) no seedea nada — así un entorno que
 * configura el número por otro medio no se rompe.
 */
class PhoneNumberSeeder extends Seeder
{
    public function run(): void
    {
        $phoneId = env('WA_PHONE_ID');

        if (! $phoneId) {
            return;
        }

        PhoneNumber::firstOrCreate(
            ['phone_number_id' => $phoneId],
            [
                'display_name' => 'Número prueba Meta',
                'waba_id'      => env('WA_WABA_ID'),
                'token'        => env('WA_TOKEN'),
                'is_active'    => true,
                'daily_limit'  => 250,
            ]
        );
    }
}
