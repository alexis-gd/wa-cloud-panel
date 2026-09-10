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

    // ── 131049: tope por usuario, NO pausa el número ─────────────────────────

    public function test_error_131049_no_pausa_el_numero_pero_aplica_hold_24h_al_contacto(): void
    {
        // 131049 es un tope de marketing POR USUARIO (frecuencia del destinatario),
        // no un problema del número. El número debe seguir enviando a los demás, pero
        // al contacto se le aplica un hold de 24h (Meta exige esperar antes de reintentar).
        $phone   = PhoneNumber::factory()->create(['is_active' => true]);
        $contact = \App\Models\Contact::factory()->create([
            'phone'  => '529231311146',
            'status' => 'active',
        ]);

        $log = MessageLog::factory()->create([
            'phone_number_id' => $phone->id,
            'to_number'       => '529231311146',
            'wa_message_id'   => 'wamid.test.131049',
            'status'          => 'sent',
        ]);

        $this->postWebhookStatus('wamid.test.131049', 'failed', [
            ['code' => 131049, 'title' => 'Message was not delivered to maintain healthy ecosystem engagement'],
        ]);

        // El número NO se pausa.
        $phone->refresh();
        $this->assertNull($phone->paused_until);
        $this->assertFalse($phone->isPaused());

        // El mensaje sí queda marcado como fallido.
        $log->refresh();
        $this->assertEquals('failed', $log->status);

        // El contacto queda con hold de ~24h.
        $contact->refresh();
        $this->assertTrue($contact->isWaMarketingHoldActive());
        $this->assertEqualsWithDelta(now()->addHours(24)->timestamp, $contact->wa_marketing_hold_until->timestamp, 60);
    }

    // ── 131050: baja a nivel WhatsApp → opt-out cross-channel ─────────────────

    public function test_error_131050_marca_opt_out_al_contacto(): void
    {
        $contact = \App\Models\Contact::factory()->create([
            'phone'  => '529231311146',
            'status' => 'active',
        ]);

        $log = MessageLog::factory()->create([
            'to_number'     => '529231311146',
            'wa_message_id' => 'wamid.test.131050',
            'status'        => 'sent',
        ]);

        $this->postWebhookStatus('wamid.test.131050', 'failed', [
            ['code' => 131050, 'title' => 'Recipient opted out of marketing messages'],
        ]);

        $contact->refresh();
        $this->assertEquals('opted_out', $contact->status);
        $this->assertEquals('whatsapp_131050', $contact->opted_out_source);
    }

    // ── 131064: límite de cuenta por categorización → pausa el número ─────────

    public function test_error_131064_pausa_el_numero_60_minutos(): void
    {
        $phone = PhoneNumber::factory()->create(['is_active' => true]);

        $log = MessageLog::factory()->create([
            'phone_number_id' => $phone->id,
            'wa_message_id'   => 'wamid.test.131064',
            'status'          => 'sent',
        ]);

        $this->postWebhookStatus('wamid.test.131064', 'failed', [
            ['code' => 131064, 'title' => 'Account has reached its messaging limit due to template categorization violations'],
        ]);

        $phone->refresh();
        $this->assertTrue($phone->isPaused());
        $this->assertEqualsWithDelta(now()->addMinutes(60)->timestamp, $phone->paused_until->timestamp, 5);
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
        $this->assertStringContainsString('marketing', $notif->body);
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
