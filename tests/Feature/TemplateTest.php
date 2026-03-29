<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_templates_requires_api_key(): void
    {
        $this->getJson('/api/templates')->assertStatus(401);
    }

    public function test_templates_returns_array_with_valid_api_key(): void
    {
        $this->actingAsOperator();

        $this->getJson('/api/templates')
             ->assertStatus(200)
             ->assertJsonIsArray();
    }
}
