<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class PhoneNumberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'display_name'    => 'Número ' . $this->faker->numberBetween(1, 99),
            'phone_number_id' => $this->faker->numerify('##########'),
            'waba_id'         => $this->faker->numerify('##########'),
            'token'           => 'fake-token-' . $this->faker->uuid(),
            'is_active'       => true,
            'daily_limit'     => 250,
        ];
    }
}
