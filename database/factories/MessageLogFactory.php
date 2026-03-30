<?php

namespace Database\Factories;

use App\Models\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'phone_number_id' => PhoneNumber::factory(),
            'to_number'       => '52' . $this->faker->numerify('##########'),
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => 'sent',
            'sent_at'         => now(),
        ];
    }
}
