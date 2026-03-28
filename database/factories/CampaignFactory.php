<?php

namespace Database\Factories;

use App\Models\PhoneNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'             => 'Campaña ' . $this->faker->words(2, true),
            'template_name'    => 'hello_world',
            'language_code'    => 'en_US',
            'body_vars'        => [],
            'phone_number_id'  => PhoneNumber::factory(),
            'status'           => 'draft',
            'total_contacts'   => 0,
            'sent_count'       => 0,
            'delivered_count'  => 0,
            'failed_count'     => 0,
            'scheduled_at'     => null,
            'started_at'       => null,
            'completed_at'     => null,
        ];
    }
}
