<?php

namespace Database\Factories;

use App\Models\SmsTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

class SmsTemplateFactory extends Factory
{
    protected $model = SmsTemplate::class;

    public function definition(): array
    {
        return [
            'name'      => 'promo_' . $this->faker->unique()->word(),
            'body'      => 'Prestamaz: prestamo desde $10,000. Responde STOP para baja.',
            'is_active' => true,
        ];
    }
}
