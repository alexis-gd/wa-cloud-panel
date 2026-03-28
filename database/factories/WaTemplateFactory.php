<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class WaTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'          => 'template_' . $this->faker->unique()->word(),
            'language_code' => 'es_MX',
            'category'      => 'MARKETING',
            'status'        => 'approved',
            'description'   => null,
            'is_active'     => true,
        ];
    }
}
