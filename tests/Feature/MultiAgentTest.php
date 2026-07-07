<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultiAgentTest extends TestCase
{
    use RefreshDatabase;

    private function createContactWithConversation(string $body = 'Hola'): Contact
    {
        $contact = Contact::factory()->create(['status' => 'active']);
        Conversation::create([
            'contact_id'   => $contact->id,
            'direction'    => 'inbound',
            'message_type' => 'text',
            'body'         => $body,
            'status'       => 'received',
            'window_open'  => true,
        ]);
        return $contact;
    }

    // ── Tiempo real: la asignacion cambia sin recargar ───────────────────────

    public function test_assign_emite_conversation_updated(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\ConversationUpdated::class]);
        $this->actingAsAdmin();

        $contact = $this->createContactWithConversation();
        $agent   = User::factory()->create(['role' => 'agent', 'is_active' => true]);

        $this->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $agent->id])->assertOk();

        \Illuminate\Support\Facades\Event::assertDispatched(
            \App\Events\ConversationUpdated::class,
            fn ($e) => $e->contactId === $contact->id,
        );
    }

    public function test_claim_emite_conversation_updated(): void
    {
        \Illuminate\Support\Facades\Event::fake([\App\Events\ConversationUpdated::class]);

        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        Sanctum::actingAs($agent);
        $contact = $this->createContactWithConversation();

        $this->postJson("/api/conversations/{$contact->id}/claim")->assertOk();

        \Illuminate\Support\Facades\Event::assertDispatched(
            \App\Events\ConversationUpdated::class,
            fn ($e) => $e->contactId === $contact->id,
        );
    }

    public function test_conversation_updated_va_al_canal_privado_conversations(): void
    {
        $event = new \App\Events\ConversationUpdated(42);

        $this->assertSame('private-conversations', $event->broadcastOn()->name);
        $this->assertSame('conversation.updated', $event->broadcastAs());
        $this->assertSame(42, $event->broadcastWith()['contact_id']);
    }

    // ── Filtro por agente ────────────────────────────────────────────────────

    public function test_admin_sees_all_conversations(): void
    {
        $this->actingAsAdmin();

        $this->createContactWithConversation('A');
        $this->createContactWithConversation('B');

        $this->getJson('/api/conversations')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_agent_sees_only_assigned_conversations(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        Sanctum::actingAs($agent);

        $contactMio  = $this->createContactWithConversation('Mía');
        $contactOtro = $this->createContactWithConversation('Otra');

        // Solo contactMio está asignado a este agente
        ConversationAssignment::create([
            'contact_id'  => $contactMio->id,
            'user_id'     => $agent->id,
            'assigned_at' => now(),
        ]);

        $res = $this->getJson('/api/conversations')->assertOk();
        $ids = collect($res->json('data'))->pluck('id');

        $this->assertContains($contactMio->id, $ids->toArray());
        $this->assertNotContains($contactOtro->id, $ids->toArray());
    }

    public function test_agent_not_shown_after_reassignment_to_other(): void
    {
        $agent1 = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $agent2 = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        Sanctum::actingAs($agent1);

        $contact = $this->createContactWithConversation('Hola');

        // Asignado a agent1, luego reasignado a agent2
        ConversationAssignment::create(['contact_id' => $contact->id, 'user_id' => $agent1->id, 'assigned_at' => now()->subMinute()]);
        ConversationAssignment::create(['contact_id' => $contact->id, 'user_id' => $agent2->id, 'assigned_at' => now()]);

        // agent1 ya no debe verla
        $res = $this->getJson('/api/conversations')->assertOk();
        $ids = collect($res->json('data'))->pluck('id');

        $this->assertNotContains($contact->id, $ids->toArray());
    }

    // ── POST /api/conversations/{id}/claim ───────────────────────────────────

    public function test_agent_can_claim_conversation(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        Sanctum::actingAs($agent);

        $contact = $this->createContactWithConversation();

        $this->postJson("/api/conversations/{$contact->id}/claim")
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.assigned_to.id', $agent->id);

        $this->assertDatabaseHas('conversation_assignments', [
            'contact_id' => $contact->id,
            'user_id'    => $agent->id,
        ]);
    }

    // ── POST /api/conversations/{id}/assign ──────────────────────────────────

    public function test_admin_can_assign_conversation_to_agent(): void
    {
        $this->actingAsAdmin();

        $contact = $this->createContactWithConversation();
        $agent   = User::factory()->create(['role' => 'agent', 'is_active' => true]);

        $this->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $agent->id])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.assigned_to.id', $agent->id);

        $this->assertDatabaseHas('conversation_assignments', [
            'contact_id' => $contact->id,
            'user_id'    => $agent->id,
        ]);
    }

    public function test_agent_cannot_assign_to_others(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        Sanctum::actingAs($agent);

        $contact = $this->createContactWithConversation();
        $other   = User::factory()->create(['role' => 'agent', 'is_active' => true]);

        $this->postJson("/api/conversations/{$contact->id}/assign", ['user_id' => $other->id])
            ->assertStatus(403);
    }

    public function test_assign_returns_404_for_unknown_contact(): void
    {
        $this->actingAsAdmin();
        $agent = User::factory()->create(['role' => 'agent', 'is_active' => true]);

        $this->postJson('/api/conversations/9999/assign', ['user_id' => $agent->id])
            ->assertNotFound();
    }

    public function test_claim_returns_404_for_unknown_contact(): void
    {
        $this->actingAsAgent();

        $this->postJson('/api/conversations/9999/claim')
            ->assertNotFound();
    }

    // ── assigned_to en index ─────────────────────────────────────────────────

    public function test_conversations_index_includes_assigned_to(): void
    {
        $this->actingAsAdmin();

        $contact = $this->createContactWithConversation();
        $agent   = User::factory()->create(['role' => 'agent', 'is_active' => true]);

        ConversationAssignment::create([
            'contact_id'  => $contact->id,
            'user_id'     => $agent->id,
            'assigned_at' => now(),
        ]);

        $res = $this->getJson('/api/conversations')->assertOk();
        $item = collect($res->json('data'))->firstWhere('id', $contact->id);

        $this->assertNotNull($item['assigned_to']);
        $this->assertEquals($agent->id, $item['assigned_to']['id']);
    }
}
