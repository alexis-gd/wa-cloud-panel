<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\PhoneNumber;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationControllerTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contact;
    private PhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contact = Contact::factory()->create([
            'phone'  => '529231311146',
            'status' => 'active',
        ]);

        $this->phone = PhoneNumber::factory()->create([
            'is_active' => true,
        ]);
    }

    private function mockSuccessfulClient(): void
    {
        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldReceive('post')->andReturn([
                'ok'   => true,
                'body' => ['messages' => [['id' => 'wamid.conv.test']]],
            ]);
        });
    }

    /** Crea un mensaje inbound reciente para abrir la ventana de 24h. */
    private function openWindow(): void
    {
        Conversation::create([
            'contact_id'   => $this->contact->id,
            'direction'    => 'inbound',
            'message_type' => 'text',
            'body'         => 'Hola, me interesa el préstamo',
            'status'       => 'received',
            'window_open'  => true,
        ]);
    }

    /**
     * Crea un mensaje inbound con timestamp en el pasado.
     * No usa create() porque created_at no está en $fillable —
     * se asigna directamente antes del save() para que Eloquent
     * no lo sobreescriba (solo auto-asigna si el campo no está dirty).
     */
    private function createOldInbound(int $hoursAgo = 25): void
    {
        $conv = new Conversation([
            'contact_id'   => $this->contact->id,
            'direction'    => 'inbound',
            'message_type' => 'text',
            'body'         => 'Mensaje antiguo',
            'status'       => 'received',
            'window_open'  => false,
        ]);
        $conv->created_at = now()->subHours($hoursAgo);
        $conv->updated_at = now()->subHours($hoursAgo);
        $conv->save();
    }

    // ── POST /api/conversations/{id}/messages ─────────────────────────────────

    public function test_send_con_ventana_abierta_guarda_mensaje_y_devuelve_201(): void
    {
        $this->openWindow();
        $this->mockSuccessfulClient();

        $this->actingAsAgent()
            ->postJson("/api/conversations/{$this->contact->id}/messages", [
                'body' => 'Hola, ¿cómo le podemos ayudar?',
            ])
            ->assertStatus(201)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.direction', 'outbound')
            ->assertJsonPath('data.status', 'sent');

        $this->assertDatabaseHas('conversations', [
            'contact_id' => $this->contact->id,
            'direction'  => 'outbound',
            'body'       => 'Hola, ¿cómo le podemos ayudar?',
            'status'     => 'sent',
        ]);
    }

    public function test_send_con_ventana_cerrada_devuelve_422_window_closed(): void
    {
        // Sin mensajes inbound recientes → ventana cerrada
        $this->actingAsAgent()
            ->postJson("/api/conversations/{$this->contact->id}/messages", ['body' => 'Hola'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'WINDOW_CLOSED');
    }

    public function test_send_con_inbound_antiguo_mayor_24h_devuelve_422_window_closed(): void
    {
        $this->createOldInbound();

        $this->actingAsAgent()
            ->postJson("/api/conversations/{$this->contact->id}/messages", ['body' => 'Hola'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'WINDOW_CLOSED');
    }

    public function test_send_con_contacto_opted_out_devuelve_422_opted_out(): void
    {
        $this->contact->update(['status' => 'opted_out']);

        $this->actingAsAgent()
            ->postJson("/api/conversations/{$this->contact->id}/messages", ['body' => 'Hola'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'OPTED_OUT');
    }

    public function test_send_sin_numero_activo_devuelve_500_no_phone_number(): void
    {
        $this->openWindow();
        $this->phone->update(['is_active' => false]);

        $this->actingAsAgent()
            ->postJson("/api/conversations/{$this->contact->id}/messages", ['body' => 'Hola'])
            ->assertStatus(500)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'NO_PHONE_NUMBER');
    }

    public function test_send_sin_body_devuelve_422_validacion(): void
    {
        $this->openWindow();

        $this->actingAsAgent()
            ->postJson("/api/conversations/{$this->contact->id}/messages", [])
            ->assertStatus(422);
    }

    public function test_send_sin_autenticacion_devuelve_401(): void
    {
        $this->postJson("/api/conversations/{$this->contact->id}/messages", ['body' => 'Hola'])
            ->assertStatus(401);
    }

    // ── GET /api/conversations ────────────────────────────────────────────────

    public function test_index_devuelve_lista_de_contactos_con_conversaciones(): void
    {
        $this->openWindow();

        // Admin y operator ven todas las conversaciones sin necesidad de asignación
        $this->actingAsOperator()
            ->getJson('/api/conversations')
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonCount(1, 'data');
    }

    public function test_index_marca_window_open_true_cuando_hay_inbound_reciente(): void
    {
        $this->openWindow();

        $response = $this->actingAsOperator()
            ->getJson('/api/conversations')
            ->assertStatus(200);

        $contactData = collect($response->json('data'))->firstWhere('id', $this->contact->id);
        $this->assertTrue($contactData['window_open']);
    }

    public function test_index_marca_window_open_false_cuando_inbound_tiene_mas_de_24h(): void
    {
        $this->createOldInbound();

        $response = $this->actingAsOperator()
            ->getJson('/api/conversations')
            ->assertStatus(200);

        $contactData = collect($response->json('data'))->firstWhere('id', $this->contact->id);
        $this->assertFalse($contactData['window_open']);
    }

    public function test_index_excluye_contactos_sin_conversaciones(): void
    {
        // $this->contact NO tiene conversaciones
        Contact::factory()->create(['phone' => '529000000001', 'status' => 'active']);

        $response = $this->actingAsAgent()
            ->getJson('/api/conversations')
            ->assertStatus(200);

        $this->assertCount(0, $response->json('data'));
    }

    public function test_index_sin_autenticacion_devuelve_401(): void
    {
        $this->getJson('/api/conversations')->assertStatus(401);
    }

    // ── GET /api/conversations/{id} ───────────────────────────────────────────

    public function test_show_devuelve_historial_y_window_open_true(): void
    {
        $this->openWindow();

        $response = $this->actingAsAgent()
            ->getJson("/api/conversations/{$this->contact->id}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.window_open', true)
            ->assertJsonPath('data.contact.id', $this->contact->id)
            ->assertJsonPath('data.contact.phone', $this->contact->phone);

        $this->assertCount(1, $response->json('data.messages'));
        $this->assertEquals('inbound', $response->json('data.messages.0.direction'));
    }

    public function test_show_window_open_false_cuando_no_hay_inbound_reciente(): void
    {
        $this->createOldInbound();

        $this->actingAsAgent()
            ->getJson("/api/conversations/{$this->contact->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.window_open', false);
    }

    public function test_show_incluye_mensajes_outbound_e_inbound(): void
    {
        $this->openWindow();

        // Agregar un outbound manualmente
        Conversation::create([
            'contact_id'   => $this->contact->id,
            'direction'    => 'outbound',
            'message_type' => 'text',
            'body'         => 'Respuesta del agente',
            'status'       => 'sent',
            'window_open'  => true,
        ]);

        $response = $this->actingAsAgent()
            ->getJson("/api/conversations/{$this->contact->id}")
            ->assertStatus(200);

        $this->assertCount(2, $response->json('data.messages'));
    }

    public function test_show_sin_autenticacion_devuelve_401(): void
    {
        $this->getJson("/api/conversations/{$this->contact->id}")->assertStatus(401);
    }

    public function test_show_contacto_inexistente_devuelve_404(): void
    {
        $this->actingAsAgent()
            ->getJson('/api/conversations/99999')
            ->assertStatus(404);
    }

    // ── Control de acceso ─────────────────────────────────────────────────────

    public function test_agent_puede_listar_y_ver_conversaciones(): void
    {
        $this->openWindow();

        $this->actingAsAgent()
            ->getJson('/api/conversations')
            ->assertStatus(200);

        $this->actingAsAgent()
            ->getJson("/api/conversations/{$this->contact->id}")
            ->assertStatus(200);
    }

    public function test_agent_puede_enviar_mensajes(): void
    {
        $this->openWindow();
        $this->mockSuccessfulClient();

        $this->actingAsAgent()
            ->postJson("/api/conversations/{$this->contact->id}/messages", [
                'body' => 'Mensaje de agente',
            ])
            ->assertStatus(201);
    }
}
