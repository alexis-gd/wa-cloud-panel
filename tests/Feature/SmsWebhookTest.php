<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function smsLog(string $messageId, string $to = '529991234567'): MessageLog
    {
        return MessageLog::create([
            'channel'       => 'sms',
            'to_number'     => $to,
            'sms_body'      => 'texto',
            'wa_message_id' => $messageId,
            'status'        => 'sent',
            'sent_at'       => now(),
        ]);
    }

    public function test_evento_delivered_actualiza_status(): void
    {
        $log = $this->smsLog('SM-1');

        $this->postJson('/api/sms/webhook', [
            'event'   => 'sms:delivered',
            'payload' => ['messageId' => 'SM-1'],
        ])->assertStatus(200);

        $this->assertSame('delivered', $log->fresh()->status);
    }

    public function test_evento_failed_registra_rebote(): void
    {
        $contact = Contact::factory()->create(['phone' => '529991234567', 'status' => 'active']);
        $this->smsLog('SM-2');

        $this->postJson('/api/sms/webhook', [
            'event'   => 'sms:failed',
            'payload' => ['messageId' => 'SM-2', 'reason' => 'no network'],
        ])->assertStatus(200);

        $this->assertSame(1, $contact->fresh()->sms_bounce_count);
    }

    public function test_rebotes_no_bloquean_con_umbral_default_cero(): void
    {
        // Default: Setting sms_auto_blacklist_bounces = 0 → nunca bloquea (cliente blando con SMS).
        $contact = Contact::factory()->create(['phone' => '529991234567', 'status' => 'active']);

        foreach (['SM-a', 'SM-b', 'SM-c'] as $id) {
            $this->smsLog($id);
            $this->postJson('/api/sms/webhook', [
                'event'   => 'sms:failed',
                'payload' => ['messageId' => $id],
            ])->assertStatus(200);
        }

        $contact->refresh();
        $this->assertSame(3, $contact->sms_bounce_count); // el contador SIEMPRE sube (reporte)
        $this->assertFalse($contact->sms_blocked);        // pero no bloquea con umbral 0
    }

    public function test_rebotes_bloquean_sms_cuando_umbral_configurado(): void
    {
        \App\Models\Setting::set('sms_auto_blacklist_bounces', 3);
        $contact = Contact::factory()->create(['phone' => '529991234567', 'status' => 'active']);

        foreach (['SM-a', 'SM-b', 'SM-c'] as $id) {
            $this->smsLog($id);
            $this->postJson('/api/sms/webhook', [
                'event'   => 'sms:failed',
                'payload' => ['messageId' => $id],
            ])->assertStatus(200);
        }

        $contact->refresh();
        $this->assertSame(3, $contact->sms_bounce_count);
        $this->assertTrue($contact->sms_blocked);
    }

    public function test_inbound_stop_marca_sms_opt_out_sin_tocar_whatsapp(): void
    {
        $contact = Contact::factory()->create(['phone' => '529991234567', 'status' => 'active']);

        $this->postJson('/api/sms/webhook', [
            'event'   => 'sms:received',
            'payload' => ['sender' => '529991234567', 'message' => 'STOP'],
        ])->assertStatus(200);

        $contact->refresh();
        $this->assertTrue($contact->sms_opt_out);
        $this->assertSame('active', $contact->status); // WhatsApp intacto

        // También queda registrado en la bandeja con acción opt_out.
        $this->assertDatabaseHas('sms_inbound_messages', [
            'contact_id' => $contact->id,
            'action'     => 'opt_out',
        ]);
    }

    public function test_inbound_texto_normal_no_hace_opt_out(): void
    {
        $contact = Contact::factory()->create(['phone' => '529991234567', 'status' => 'active']);

        $this->postJson('/api/sms/webhook', [
            'event'   => 'sms:received',
            'payload' => ['sender' => '529991234567', 'message' => 'no me interesa gracias'],
        ])->assertStatus(200);

        $this->assertFalse($contact->fresh()->sms_opt_out);

        // Se registra en la bandeja sin acción.
        $this->assertDatabaseHas('sms_inbound_messages', [
            'contact_id' => $contact->id,
            'body'       => 'no me interesa gracias',
            'action'     => null,
        ]);
    }

    public function test_inbound_de_numero_desconocido_se_registra_sin_contacto(): void
    {
        $this->postJson('/api/sms/webhook', [
            'event'   => 'sms:received',
            'payload' => ['sender' => '528881112233', 'message' => 'hola'],
        ])->assertStatus(200);

        $this->assertDatabaseHas('sms_inbound_messages', [
            'contact_id'  => null,
            'from_number' => '528881112233',
            'body'        => 'hola',
        ]);
    }

    // ── Tiempo real ──────────────────────────────────────────────────────────────

    public function test_respuesta_sms_de_contacto_emite_evento_tiempo_real(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\InboundMessageReceived::class]);

        $contact = Contact::factory()->create(['phone' => '529991234567', 'status' => 'active']);

        $this->postJson('/api/sms/webhook', [
            'event'   => 'sms:received',
            'payload' => ['sender' => '529991234567', 'message' => 'Si me interesa'],
        ])->assertStatus(200);

        \Illuminate\Support\Facades\Event::assertDispatched(
            \App\Events\InboundMessageReceived::class,
            fn ($e) => $e->contactId === $contact->id
                && $e->channel === 'sms'
                && str_contains($e->body, 'interesa'),
        );
    }

    public function test_respuesta_sms_de_numero_desconocido_no_emite_evento(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\InboundMessageReceived::class]);

        $this->postJson('/api/sms/webhook', [
            'event'   => 'sms:received',
            'payload' => ['sender' => '528881112233', 'message' => 'hola'],
        ])->assertStatus(200);

        \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\InboundMessageReceived::class);
    }

    public function test_webhook_registra_ultimo_evento_para_health(): void
    {
        $this->smsLog('SM-h');

        $this->postJson('/api/sms/webhook', [
            'event'   => 'sms:delivered',
            'payload' => ['messageId' => 'SM-h'],
        ])->assertStatus(200);

        $this->assertSame('sms:delivered', \App\Models\Setting::get('sms_webhook_last_event'));
        $this->assertNotNull(\App\Models\Setting::get('sms_webhook_last_at'));
    }

    public function test_firma_invalida_es_rechazada(): void
    {
        config(['sms.webhook_secret' => 'shh']);
        $this->smsLog('SM-9');

        // Sin header X-Signature válido → 403
        $this->postJson('/api/sms/webhook', [
            'event'   => 'sms:delivered',
            'payload' => ['messageId' => 'SM-9'],
        ])->assertStatus(403);
    }

    public function test_firma_valida_es_aceptada(): void
    {
        config(['sms.webhook_secret' => 'shh']);
        $log = $this->smsLog('SM-10');

        $body      = json_encode(['event' => 'sms:delivered', 'payload' => ['messageId' => 'SM-10']]);
        $timestamp = (string) time();
        // capcom6 firma HMAC-SHA256 sobre (body + timestamp).
        $signature = hash_hmac('sha256', $body . $timestamp, 'shh');

        $this->call('POST', '/api/sms/webhook', [], [], [], [
            'CONTENT_TYPE'     => 'application/json',
            'HTTP_ACCEPT'      => 'application/json',
            'HTTP_X_SIGNATURE' => $signature,
            'HTTP_X_TIMESTAMP' => $timestamp,
        ], $body)->assertStatus(200);

        $this->assertSame('delivered', $log->fresh()->status);
    }
}
