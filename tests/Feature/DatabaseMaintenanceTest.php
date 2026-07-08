<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MessageLog;
use App\Models\SmsInboundMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    // ── Seeders (migrate:fresh --seed) ───────────────────────────────────────

    public function test_seeder_crea_5_usuarios_por_rol_dominio_prestamaz(): void
    {
        $this->seed();

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseHas('users', ['email' => 'superadmin@prestamaz.mx', 'role' => 'superadmin']);
        $this->assertDatabaseHas('users', ['email' => 'admin@prestamaz.mx',      'role' => 'admin']);
        $this->assertDatabaseHas('users', ['email' => 'operador@prestamaz.mx',   'role' => 'operator']);
        $this->assertSame(2, User::where('role', 'agent')->count());
    }

    public function test_seeder_crea_los_contactos_base(): void
    {
        $this->seed();

        $this->assertDatabaseCount('contacts', 4);
        $this->assertDatabaseHas('contacts', ['phone' => '529231311146', 'name' => 'Juan Pérez']);
        $this->assertDatabaseHas('contacts', ['phone' => '529231122058', 'name' => 'Alexis Garcia 2']);
    }

    public function test_seeder_es_idempotente(): void
    {
        $this->seed();
        $this->seed(); // segunda corrida no duplica

        $this->assertDatabaseCount('users', 5);
        $this->assertDatabaseCount('contacts', 4);
    }

    // ── db:clean-demo ────────────────────────────────────────────────────────

    public function test_clean_demo_borra_transaccional_y_conserva_base(): void
    {
        $user     = User::factory()->create();
        $contact  = Contact::factory()->create();
        $campaign = Campaign::factory()->create();

        MessageLog::create([
            'to_number' => $contact->phone, 'channel' => 'sms', 'status' => 'sent',
            'campaign_id' => $campaign->id, 'sent_at' => now(),
        ]);
        Conversation::create([
            'contact_id' => $contact->id, 'direction' => 'inbound',
            'message_type' => 'text', 'body' => 'tonteras', 'status' => 'received', 'window_open' => true,
        ]);
        SmsInboundMessage::create([
            'contact_id' => $contact->id, 'from_number' => $contact->phone,
            'body' => 'mas tonteras', 'action' => null, 'received_at' => now(),
        ]);
        AppNotification::create(['type' => 'delivery_failed', 'title' => 'x', 'body' => 'y']);

        $this->artisan('db:clean-demo --force')->assertExitCode(0);

        // Transaccional: vacío
        $this->assertDatabaseCount('message_log', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('sms_inbound_messages', 0);
        $this->assertDatabaseCount('app_notifications', 0);
        $this->assertDatabaseCount('campaigns', 0);

        // Base: intacto
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id]);
    }
}
