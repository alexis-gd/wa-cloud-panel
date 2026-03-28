<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{

    public function test_health_endpoint_returns_ok(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
                 ->assertJsonFragment(['status' => 'ok']);
    }

    public function test_health_endpoint_does_not_require_api_key(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200);
    }
}
