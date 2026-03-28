<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_templates_requires_api_key(): void
    {
        $response = $this->getJson('/api/templates');

        $response->assertStatus(401);
    }

    public function test_templates_returns_array_with_valid_api_key(): void
    {
        config(['services.whatsapp.api_key' => 'test-api-key']);

        $response = $this->withHeader('X-API-Key', 'test-api-key')
                         ->getJson('/api/templates');

        $response->assertStatus(200)
                 ->assertJsonIsArray();
    }
}
