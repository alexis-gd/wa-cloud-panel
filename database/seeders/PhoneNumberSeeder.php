<?php

namespace Database\Seeders;

use App\Models\PhoneNumber;
use Illuminate\Database\Seeder;

class PhoneNumberSeeder extends Seeder
{
    public function run(): void
    {
        PhoneNumber::create([
            'display_name'    => 'Número prueba Meta',
            'phone_number_id' => env('WA_PHONE_ID'),
            'waba_id'         => env('WA_WABA_ID'),
            'token'           => env('WA_TOKEN'),
            'is_active'       => true,
            'daily_limit'     => 250,
        ]);
    }
}
