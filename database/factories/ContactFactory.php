<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    public function definition(): array
    {
        // Generar número mexicano válido: 52 + 10 dígitos
        $phone = '52' . $this->faker->numerify('##########');

        return [
            'phone'       => $phone,
            'name'        => $this->faker->name(),
            'status'      => 'active',
            'source'      => 'excel',
            'notes'       => null,
            'opted_out_at' => null,
        ];
    }
}
