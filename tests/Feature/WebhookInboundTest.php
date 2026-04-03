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

    // ── Normalización de número (521XXXXXXXXX → 52XXXXXXXXX) ─────────────────

    public function test_numero_de_13_digitos_521_encuentra_contacto_guardado_como_12_digitos(): void
    {
        // Contacto guardado como 529231311146 (12 dígitos)
        // Meta envía 5219231311146 (13 dígitos — añade "1" después de "52" en móviles México)
        $payload = $this->inboundPayload('5219231311146', 'text', [
            'text' => ['body' => 'Hola, me interesa el préstamo'],
        ]);

        $this->postWebhook($payload)->assertStatus(200);

        $this->assertDatabaseHas('conversations', [
            'contact_id' => $this->contact->id,
            'direction'  => 'inbound',
            'body'       => 'Hola, me interesa el préstamo',
        ]);
    }

    public function test_numero_de_12_digitos_52_no_se_normaliza(): void
    {
        // 529231311146 ya tiene 12 dígitos — no debe modificarse
        $payload = $this->inboundPayload('529231311146', 'text', [
            'text' => ['body' => 'Consulta sobre préstamo'],
        ]);

        $this->postWebhook($payload)->assertStatus(200);

        $this->assertDatabaseHas('conversations', [
            'contact_id' => $this->contact->id,
            'direction'  => 'inbound',
        ]);
    }

    // ── Tipo button (Quick Reply de plantilla) ────────────────────────────────

    public function test_mensaje_tipo_button_se_guarda_con_body_y_message_type_correctos(): void
    {
        // type=button es la respuesta de Meta cuando el usuario toca un botón de plantilla
        $payload = $this->inboundPayload('529231311146', 'button', [
            'button' => [
                'text'    => 'Me interesa',
                'payload' => 'interested',
            ],
        ]);

        $this->postWebhook($payload)->assertStatus(200);

        $this->assertDatabaseHas('conversations', [
            'contact_id'   => $this->contact->id,
            'direction'    => 'inbound',
            'body'         => 'Me interesa',
            'message_type' => 'button_reply',
        ]);
    }

    public function test_mensaje_tipo_button_no_hace_opt_out(): void
    {
        // "NO" como botón no es opt-out — solo lo es como texto exacto
        $payload = $this->inboundPayload('529231311146', 'button', [
            'button' => [
                'text'    => 'NO',
                'payload' => 'decline',
            ],
        ]);

        $this->postWebhook($payload)->assertStatus(200);

        // El contacto sigue activo — opt-out solo aplica para texto libre
        $this->assertDatabaseHas('contacts', [
            'id'     => $this->contact->id,
            'status' => 'active',
        ]);
    }
}
