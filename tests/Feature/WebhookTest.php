<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
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

    // ── Delivery error persistence ────────────────────────────────────────────

    public function test_failed_status_persists_error_code_and_title(): void
    {
        $log = MessageLog::factory()->create([
            'wa_message_id' => 'wamid.test.failed',
            'status'        => 'sent',
        ]);

        $this->postWebhookStatus('wamid.test.failed', 'failed', [
            ['code' => 131026, 'title' => 'Recipient phone number not in allowed list'],
        ]);

        $log->refresh();
        $this->assertEquals('failed', $log->status);
        $this->assertEquals(131026, $log->delivery_error_code);
        $this->assertEquals('Recipient phone number not in allowed list', $log->delivery_error_title);
    }

    public function test_failed_status_without_error_data_still_updates_status(): void
    {
        $log = MessageLog::factory()->create([
            'wa_message_id' => 'wamid.test.noerrdata',
            'status'        => 'sent',
        ]);

        $this->postWebhookStatus('wamid.test.noerrdata', 'failed', []);

        $log->refresh();
        $this->assertEquals('failed', $log->status);
        $this->assertNull($log->delivery_error_code);
    }

    public function test_delivered_status_does_not_set_error_columns(): void
    {
        $log = MessageLog::factory()->create([
            'wa_message_id' => 'wamid.test.delivered',
            'status'        => 'sent',
        ]);

        $this->postWebhookStatus('wamid.test.delivered', 'delivered', []);

        $log->refresh();
        $this->assertEquals('delivered', $log->status);
        $this->assertNull($log->delivery_error_code);
    }

    // ── 131049 circuit breaker ────────────────────────────────────────────────

    public function test_error_131049_pauses_phone_number_60_minutes(): void
    {
        $phone = PhoneNumber::factory()->create(['is_active' => true]);

        $log = MessageLog::factory()->create([
            'phone_number_id' => $phone->id,
            'wa_message_id'   => 'wamid.test.131049',
            'status'          => 'sent',
        ]);

        $this->postWebhookStatus('wamid.test.131049', 'failed', [
            ['code' => 131049, 'title' => 'Message failed to send because there were too many messages sent from this phone number in a short period of time'],
        ]);

        $phone->refresh();
        $this->assertNotNull($phone->paused_until);
        $this->assertTrue($phone->isPaused());
        $this->assertEqualsWithDelta(now()->addMinutes(60)->timestamp, $phone->paused_until->timestamp, 5);
    }

    public function test_error_131049_does_not_double_pause_already_paused_number(): void
    {
        $pausedUntil = now()->addMinutes(90);
        $phone = PhoneNumber::factory()->create([
            'is_active'    => true,
            'paused_until' => $pausedUntil,
        ]);

        $log = MessageLog::factory()->create([
            'phone_number_id' => $phone->id,
            'wa_message_id'   => 'wamid.test.131049.double',
            'status'          => 'sent',
        ]);

        $this->postWebhookStatus('wamid.test.131049.double', 'failed', [
            ['code' => 131049, 'title' => 'Quality limit hit'],
        ]);

        $phone->refresh();
        // paused_until should NOT be reset (already paused longer)
        $this->assertEqualsWithDelta($pausedUntil->timestamp, $phone->paused_until->timestamp, 5);
    }

    public function test_other_error_codes_do_not_pause_phone_number(): void
    {
        $phone = PhoneNumber::factory()->create(['is_active' => true]);

        $log = MessageLog::factory()->create([
            'phone_number_id' => $phone->id,
            'wa_message_id'   => 'wamid.test.131026',
            'status'          => 'sent',
        ]);

        $this->postWebhookStatus('wamid.test.131026', 'failed', [
            ['code' => 131026, 'title' => 'Number not on WhatsApp'],
        ]);

        $phone->refresh();
        $this->assertNull($phone->paused_until);
    }

    // ── Notification creation ─────────────────────────────────────────────────

    public function test_failed_delivery_creates_notification(): void
    {
        $log = MessageLog::factory()->create([
            'wa_message_id' => 'wamid.test.notif',
            'to_number'     => '529231311146',
            'status'        => 'sent',
        ]);

        $this->postWebhookStatus('wamid.test.notif', 'failed', [
            ['code' => 131049, 'title' => 'Quality limit hit'],
        ]);

        $this->assertDatabaseHas('app_notifications', [
            'type'  => 'delivery_failed',
            'title' => 'Entrega fallida',
        ]);

        $notif = AppNotification::first();
        $this->assertStringContainsString('529231311146', $notif->body);
        $this->assertStringContainsString('calidad', $notif->body);
    }

    public function test_delivered_status_does_not_create_notification(): void
    {
        $log = MessageLog::factory()->create([
            'wa_message_id' => 'wamid.test.nonotif',
            'status'        => 'sent',
        ]);

        $this->postWebhookStatus('wamid.test.nonotif', 'delivered', []);

        $this->assertDatabaseCount('app_notifications', 0);
    }

    // ── Tiempo real: estado de entrega de campaña ────────────────────────────

    public function test_status_de_mensaje_de_campana_emite_progreso(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\CampaignProgressUpdated::class]);

        $campaign = \App\Models\Campaign::factory()->create(['status' => 'completed']);
        MessageLog::factory()->create([
            'wa_message_id' => 'wamid.camp.delivered',
            'campaign_id'   => $campaign->id,
            'status'        => 'sent',
        ]);

        $this->postWebhookStatus('wamid.camp.delivered', 'delivered', []);

        \Illuminate\Support\Facades\Event::assertDispatched(
            \App\Events\CampaignProgressUpdated::class,
            fn ($e) => $e->campaignId === $campaign->id,
        );
    }

    public function test_status_de_mensaje_sin_campana_no_emite_progreso(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\CampaignProgressUpdated::class]);

        MessageLog::factory()->create([
            'wa_message_id' => 'wamid.nocamp.delivered',
            'campaign_id'   => null,
            'status'        => 'sent',
        ]);

        $this->postWebhookStatus('wamid.nocamp.delivered', 'delivered', []);

        \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\CampaignProgressUpdated::class);
    }

    public function test_status_de_entrega_emite_conversation_updated(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\ConversationUpdated::class]);

        $contact = \App\Models\Contact::factory()->create();
        \App\Models\Conversation::create([
            'contact_id'    => $contact->id,
            'direction'     => 'outbound',
            'message_type'  => 'text',
            'body'          => 'Hola',
            'wa_message_id' => 'wamid.conv.delivered',
            'status'        => 'sent',
            'window_open'   => true,
        ]);

        $this->postWebhookStatus('wamid.conv.delivered', 'read', []);

        \Illuminate\Support\Facades\Event::assertDispatched(
            \App\Events\ConversationUpdated::class,
            fn ($e) => $e->contactId === $contact->id,
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function postWebhookStatus(string $waMessageId, string $status, array $errors): void
    {
        $statusEvent = ['id' => $waMessageId, 'status' => $status];
        if (! empty($errors)) {
            $statusEvent['errors'] = $errors;
        }

        $data = [
            'entry' => [[
                'changes' => [[
                    'value' => ['statuses' => [$statusEvent]],
                ]],
            ]],
        ];

        $payload   = json_encode($data);
        $secret    = config('services.whatsapp.app_secret', 'test_secret');
        $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        $this->withHeader('X-Hub-Signature-256', $signature)
             ->postJson('/api/webhook', $data)
             ->assertStatus(200);
    }
}
