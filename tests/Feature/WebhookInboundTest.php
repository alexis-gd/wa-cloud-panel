<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookInboundTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contact = Contact::factory()->create([
            'phone'  => '529231311146',
            'status' => 'active',
        ]);
    }

    private function postWebhook(array $body): \Illuminate\Testing\TestResponse
    {
        $content   = json_encode($body);
        $secret    = config('services.whatsapp.app_secret', 'test_secret');
        $signature = 'sha256=' . hash_hmac('sha256', $content, $secret);

        return $this->postJson('/api/webhook', $body, [
            'X-Hub-Signature-256' => $signature,
        ]);
    }

    private function inboundPayload(string $from, string $type, array $extra = []): array
    {
        $message = array_merge(['from' => $from, 'id' => 'wamid.test', 'type' => $type], $extra);

        return [
            'entry' => [[
                'changes' => [[
                    'value' => ['messages' => [$message]],
                ]],
            ]],
        ];
    }

    // ── Opt-out por texto ─────────────────────────────────────────────────────

    /** @dataProvider optOutWords */
    public function test_texto_opt_out_marca_contacto(string $word): void
    {
        $payload = $this->inboundPayload('529231311146', 'text', ['text' => ['body' => $word]]);

        $this->postWebhook($payload)->assertStatus(200);

        $this->assertDatabaseHas('contacts', [
            'id'     => $this->contact->id,
            'status' => 'opted_out',
        ]);
    }

    public static function optOutWords(): array
    {
        return [
            ['STOP'], ['stop'], ['Baja'], ['BAJA'], ['CANCELAR'], ['NO'],
        ];
    }

    public function test_texto_no_por_ahora_no_hace_opt_out(): void
    {
        $payload = $this->inboundPayload('529231311146', 'text', ['text' => ['body' => 'no por ahora']]);

        $this->postWebhook($payload)->assertStatus(200);

        $this->assertDatabaseHas('contacts', [
            'id'     => $this->contact->id,
            'status' => 'active', // no cambió
        ]);
    }

    public function test_texto_con_no_embebido_no_hace_opt_out(): void
    {
        foreach (['no me cae', 'tengo uno', 'bueno gracias', 'no tengo dinero aún'] as $text) {
            $payload = $this->inboundPayload('529231311146', 'text', ['text' => ['body' => $text]]);
            $this->postWebhook($payload)->assertStatus(200);
        }

        $this->assertDatabaseHas('contacts', ['id' => $this->contact->id, 'status' => 'active']);
    }

    // ── Snooze por botón ──────────────────────────────────────────────────────

    public function test_boton_no_por_ahora_activa_snooze(): void
    {
        Setting::set('cooldown_days', '30');

        $payload = $this->inboundPayload('529231311146', 'interactive', [
            'interactive' => [
                'type'         => 'button_reply',
                'button_reply' => ['id' => 'snooze', 'title' => 'No por ahora'],
            ],
        ]);

        $this->postWebhook($payload)->assertStatus(200);

        $this->contact->refresh();
        $this->assertNotNull($this->contact->snoozed_until);
        $this->assertTrue($this->contact->isSnoozeActive());
        $this->assertSame('active', $this->contact->status); // no es opt-out
    }

    public function test_boton_me_interesa_no_cambia_status(): void
    {
        $payload = $this->inboundPayload('529231311146', 'interactive', [
            'interactive' => [
                'type'         => 'button_reply',
                'button_reply' => ['id' => 'interested', 'title' => 'Me interesa'],
            ],
        ]);

        $this->postWebhook($payload)->assertStatus(200);

        $this->assertDatabaseHas('contacts', ['id' => $this->contact->id, 'status' => 'active']);
    }

    // ── Mensaje guardado en conversations ────────────────────────────────────

    public function test_mensaje_entrante_se_guarda_en_conversations(): void
    {
        $payload = $this->inboundPayload('529231311146', 'text', ['text' => ['body' => 'Hola, me interesa']]);

        $this->postWebhook($payload)->assertStatus(200);

        $this->assertDatabaseHas('conversations', [
            'contact_id' => $this->contact->id,
            'direction'  => 'inbound',
            'body'       => 'Hola, me interesa',
        ]);
    }

    // ── Contacto desconocido ──────────────────────────────────────────────────

    public function test_mensaje_de_numero_desconocido_se_ignora(): void
    {
        $payload = $this->inboundPayload('521111111111', 'text', ['text' => ['body' => 'STOP']]);

        $this->postWebhook($payload)->assertStatus(200); // no explota

        $this->assertDatabaseCount('conversations', 0);
    }
}
