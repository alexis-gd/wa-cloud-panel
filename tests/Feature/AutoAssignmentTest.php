<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ConversationAssignment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoAssignmentTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function postWebhook(array $body): \Illuminate\Testing\TestResponse
    {
        $content   = json_encode($body);
        $secret    = config('services.whatsapp.app_secret', 'test_secret');
        $signature = 'sha256=' . hash_hmac('sha256', $content, $secret);

        return $this->postJson('/api/webhook', $body, [
            'X-Hub-Signature-256' => $signature,
        ]);
    }

    private function inboundPayload(string $from, string $text = 'Hola'): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'from' => $from,
                            'id'   => 'wamid.test',
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function createContact(string $phone = '529231311146'): Contact
    {
        return Contact::factory()->create(['phone' => $phone, 'status' => 'active']);
    }

    // ── Auto-asignación al recibir primer mensaje ─────────────────────────────

    public function test_primer_mensaje_asigna_al_agente_disponible(): void
    {
        $agent   = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $contact = $this->createContact();

        $this->postWebhook($this->inboundPayload($contact->phone))->assertStatus(200);

        $this->assertDatabaseHas('conversation_assignments', [
            'contact_id' => $contact->id,
            'user_id'    => $agent->id,
        ]);
    }

    public function test_segundo_mensaje_no_reasigna(): void
    {
        $agent   = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $contact = $this->createContact();

        // Primera asignación manual (simula que ya fue asignada antes)
        ConversationAssignment::create([
            'contact_id'  => $contact->id,
            'user_id'     => $agent->id,
            'assigned_at' => now(),
        ]);

        $this->postWebhook($this->inboundPayload($contact->phone))->assertStatus(200);

        // Sigue habiendo exactamente 1 registro, no se creó uno nuevo
        $this->assertSame(
            1,
            ConversationAssignment::where('contact_id', $contact->id)->count()
        );
    }

    public function test_sin_agentes_activos_no_asigna_y_no_falla(): void
    {
        $contact = $this->createContact();

        // No hay agentes — el webhook debe seguir respondiendo 200
        $this->postWebhook($this->inboundPayload($contact->phone))->assertStatus(200);

        $this->assertDatabaseMissing('conversation_assignments', [
            'contact_id' => $contact->id,
        ]);
    }

    public function test_agente_inactivo_no_recibe_asignacion(): void
    {
        User::factory()->create(['role' => 'agent', 'is_active' => false]);
        $contact = $this->createContact();

        $this->postWebhook($this->inboundPayload($contact->phone))->assertStatus(200);

        $this->assertDatabaseMissing('conversation_assignments', [
            'contact_id' => $contact->id,
        ]);
    }

    // ── Modo least_chats ──────────────────────────────────────────────────────

    public function test_least_chats_asigna_al_agente_con_menos_conversaciones(): void
    {
        Setting::set('assignment_mode', 'least_chats');

        $agentBusy = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $agentFree = User::factory()->create(['role' => 'agent', 'is_active' => true]);

        // agentBusy ya tiene una conversación asignada
        $existingContact = $this->createContact('529231000001');
        ConversationAssignment::create([
            'contact_id'  => $existingContact->id,
            'user_id'     => $agentBusy->id,
            'assigned_at' => now(),
        ]);

        // Llega mensaje de contacto nuevo → debe ir a agentFree
        $newContact = $this->createContact('529231000002');
        $this->postWebhook($this->inboundPayload($newContact->phone))->assertStatus(200);

        $this->assertDatabaseHas('conversation_assignments', [
            'contact_id' => $newContact->id,
            'user_id'    => $agentFree->id,
        ]);
    }

    // ── Modo first_available ──────────────────────────────────────────────────

    public function test_first_available_asigna_al_primer_agente(): void
    {
        Setting::set('assignment_mode', 'first_available');

        $agent1 = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        User::factory()->create(['role' => 'agent', 'is_active' => true]);

        $contact = $this->createContact();
        $this->postWebhook($this->inboundPayload($contact->phone))->assertStatus(200);

        // El primer agente creado es el que recibe (primer disponible por ID)
        $this->assertDatabaseHas('conversation_assignments', [
            'contact_id' => $contact->id,
            'user_id'    => $agent1->id,
        ]);
    }

    // ── API settings/assignment-mode ─────────────────────────────────────────

    public function test_admin_puede_leer_assignment_mode(): void
    {
        $this->actingAsSuperAdmin();
        Setting::set('assignment_mode', 'first_available');

        $this->getJson('/api/settings/assignment-mode')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.assignment_mode', 'first_available');
    }

    public function test_admin_puede_cambiar_assignment_mode(): void
    {
        $this->actingAsSuperAdmin();

        $this->putJson('/api/settings/assignment-mode', ['assignment_mode' => 'first_available'])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertSame('first_available', Setting::get('assignment_mode'));
    }

    public function test_assignment_mode_invalido_retorna_422(): void
    {
        $this->actingAsSuperAdmin();

        $this->putJson('/api/settings/assignment-mode', ['assignment_mode' => 'round_robin'])
            ->assertStatus(422);
    }

    public function test_agent_no_puede_cambiar_assignment_mode(): void
    {
        $this->actingAsAgent();

        $this->putJson('/api/settings/assignment-mode', ['assignment_mode' => 'first_available'])
            ->assertStatus(403);
    }
}
