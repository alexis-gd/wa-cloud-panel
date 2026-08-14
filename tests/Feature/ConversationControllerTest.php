<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MessageLog;
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

    /** Crea un mensaje entrante con fecha fija (Conversation no tiene factory). */
    private function inbound(int $contactId, string $body, \Illuminate\Support\Carbon $at): void
    {
        $conv = new Conversation([
            'contact_id'   => $contactId,
            'direction'    => 'inbound',
            'message_type' => 'text',
            'body'         => $body,
            'status'       => 'received',
            'window_open'  => true,
        ]);
        $conv->created_at = $at;
        $conv->updated_at = $at;
        $conv->save();
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

    // ── GET /api/conversations/assignable-users ───────────────────────────────
    // Regresión: el desplegable "Asignar a" se llenaba con GET /users, que es solo admin.
    // El operador (que SÍ puede asignar) recibía 403 y el selector salía vacío.

    public function test_operator_puede_ver_a_quien_asignar(): void
    {
        $response = $this->actingAsOperator()
            ->getJson('/api/conversations/assignable-users')
            ->assertStatus(200)
            ->assertJsonPath('status', 'ok');

        $this->assertNotEmpty($response->json('data'), 'El operador debe recibir usuarios para asignar');
    }

    public function test_assignable_users_no_expone_correo_ni_rol(): void
    {
        $primero = $this->actingAsOperator()
            ->getJson('/api/conversations/assignable-users')
            ->json('data.0');

        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($primero));
    }

    public function test_agent_no_puede_ver_a_quien_asignar(): void
    {
        $this->actingAsAgent()
            ->getJson('/api/conversations/assignable-users')
            ->assertStatus(403);
    }

    /** La ruta no debe colisionar con /conversations/{contactId}. */
    public function test_assignable_users_no_se_confunde_con_el_detalle_de_una_conversacion(): void
    {
        $data = $this->actingAsOperator()
            ->getJson('/api/conversations/assignable-users')
            ->assertStatus(200)
            ->json('data');

        // El detalle devolvería 'contact'/'messages'; esto debe ser una lista de usuarios.
        $this->assertIsList($data);
    }

    /**
     * Regresión: el último mensaje se cargaba con `->limit(1)` sobre el hasMany, y en un eager
     * load eso limita la consulta ENTERA a una fila. Resultado: solo un contacto de toda la
     * lista traía vista previa y fecha; el resto salía en blanco y sin poder ordenarse.
     */
    public function test_index_devuelve_el_ultimo_mensaje_de_cada_contacto(): void
    {
        $ana  = Contact::factory()->create(['phone' => '529000000010', 'name' => 'Ana']);
        $luis = Contact::factory()->create(['phone' => '529000000011', 'name' => 'Luis']);

        $this->inbound($ana->id,  'Mensaje de Ana',  now()->subMinutes(30));
        $this->inbound($luis->id, 'Mensaje de Luis', now()->subMinutes(10));

        $data = collect($this->actingAsOperator()->getJson('/api/conversations')->json('data'));

        $this->assertEquals('Mensaje de Ana',  $data->firstWhere('id', $ana->id)['last_message']);
        $this->assertEquals('Mensaje de Luis', $data->firstWhere('id', $luis->id)['last_message']);
    }

    /** El más reciente arriba, como en WhatsApp. */
    public function test_index_ordena_por_ultimo_mensaje_mas_reciente(): void
    {
        $viejo   = Contact::factory()->create(['phone' => '529000000020']);
        $reciente = Contact::factory()->create(['phone' => '529000000021']);

        $this->inbound($viejo->id,    'Hace dos dias', now()->subDays(2));
        $this->inbound($reciente->id, 'Hace un rato',  now()->subMinutes(5));

        $ids = collect($this->actingAsOperator()->getJson('/api/conversations')->json('data'))
            ->pluck('id')
            ->all();

        $this->assertEquals($reciente->id, $ids[0]);
        $this->assertLessThan(
            array_search($viejo->id, $ids, true),
            array_search($reciente->id, $ids, true),
            'El contacto con el mensaje más reciente debe ir antes que el viejo'
        );
    }

    /** Un mensaje nuevo mueve al contacto al principio, sin importar dónde estaba. */
    public function test_index_sube_al_contacto_cuando_llega_un_mensaje_nuevo(): void
    {
        $otro = Contact::factory()->create(['phone' => '529000000030']);
        $this->inbound($otro->id, 'Mensaje del otro', now()->subMinutes(1));

        $this->createOldInbound(); // $this->contact queda hasta abajo (inbound de hace 25h)

        $ids = collect($this->actingAsOperator()->getJson('/api/conversations')->json('data'))->pluck('id')->all();
        $this->assertEquals($otro->id, $ids[0]);

        $this->inbound($this->contact->id, 'Ya me interesa', now());

        $ids = collect($this->actingAsOperator()->getJson('/api/conversations')->json('data'))->pluck('id')->all();
        $this->assertEquals($this->contact->id, $ids[0]);
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

    public function test_send_con_numero_pausado_devuelve_422_phone_paused(): void
    {
        $this->openWindow();
        $this->phone->update(['paused_until' => now()->addHours(1)]);

        $response = $this->actingAsAgent()
            ->postJson("/api/conversations/{$this->contact->id}/messages", ['body' => 'Hola'])
            ->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'PHONE_PAUSED');

        $this->assertStringContainsString('Meta bloqueó', $response->json('message'));
    }

    public function test_send_bloqueado_si_ultimo_mensaje_fue_failed_con_error_conocido(): void
    {
        $this->openWindow();

        MessageLog::factory()->create([
            'to_number'           => $this->contact->phone,
            'status'              => 'failed',
            'delivery_error_code' => 131049,
            'delivery_error_title' => 'Quality rate limit hit',
        ]);

        $response = $this->actingAsAgent()
            ->postJson("/api/conversations/{$this->contact->id}/messages", ['body' => 'Hola'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DELIVERY_BLOCKED');

        $this->assertStringContainsString('calidad', $response->json('message'));
    }

    public function test_send_permitido_si_ultimo_mensaje_fue_delivered(): void
    {
        $this->openWindow();
        $this->mockSuccessfulClient();

        MessageLog::factory()->create([
            'to_number' => $this->contact->phone,
            'status'    => 'delivered',
        ]);

        $this->actingAsAgent()
            ->postJson("/api/conversations/{$this->contact->id}/messages", ['body' => 'Hola'])
            ->assertStatus(201);
    }

    public function test_send_permitido_si_fallo_sin_error_code(): void
    {
        $this->openWindow();
        $this->mockSuccessfulClient();

        // failed sin delivery_error_code (ej: error de API directo, no de webhook) no bloquea
        MessageLog::factory()->create([
            'to_number'           => $this->contact->phone,
            'status'              => 'failed',
            'delivery_error_code' => null,
        ]);

        $this->actingAsAgent()
            ->postJson("/api/conversations/{$this->contact->id}/messages", ['body' => 'Hola'])
            ->assertStatus(201);
    }
}
