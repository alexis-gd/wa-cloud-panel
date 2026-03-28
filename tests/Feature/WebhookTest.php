<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_verify_returns_challenge_with_correct_token(): void
    {
        config(['services.whatsapp.webhook_verify_token' => 'test-verify-token']);

        $response = $this->get('/api/webhook?' . http_build_query([
            'hub_mode'         => 'subscribe',
            'hub_verify_token' => 'test-verify-token',
            'hub_challenge'    => 'challenge_abc123',
        ]));

        $response->assertStatus(200)
                 ->assertSee('challenge_abc123');
    }

    public function test_webhook_verify_returns_403_with_wrong_token(): void
    {
        config(['services.whatsapp.webhook_verify_token' => 'test-verify-token']);

        $response = $this->get('/api/webhook?' . http_build_query([
            'hub_mode'         => 'subscribe',
            'hub_verify_token' => 'wrong-token',
            'hub_challenge'    => 'challenge_abc123',
        ]));

        $response->assertStatus(403);
    }

    public function test_webhook_post_without_signature_returns_403(): void
    {
        $response = $this->postJson('/api/webhook', ['entry' => []]);

        $response->assertStatus(403);
    }

    public function test_webhook_post_with_valid_signature_returns_200(): void
    {
        config(['services.whatsapp.app_secret' => 'test-app-secret']);

        $data      = ['entry' => []];
        $payload   = json_encode($data);
        $signature = 'sha256=' . hash_hmac('sha256', $payload, 'test-app-secret');

        $response = $this->withHeader('X-Hub-Signature-256', $signature)
                         ->postJson('/api/webhook', $data);

        $response->assertStatus(200);
    }
}
