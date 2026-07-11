<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Services\WhatsApp\TemplateBuilder;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignDetailTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;
    private PhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phone = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 250]);
        $this->campaign = Campaign::factory()->create([
            'phone_number_id' => $this->phone->id,
            'status'          => 'running',
            'total_contacts'  => 3,
            'sent_count'      => 1,
            'failed_count'    => 1,
        ]);

        // Reloj dentro de la ventana de envío (miércoles 12:00 CST): algunos tests corren
        // el job real y el guardia de horario reencolaría fuera de ventana.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-07-08 12:00:00', 'America/Mexico_City'));
    }

    private function createLog(string $status, ?string $discardReason = null): MessageLog
    {
        return MessageLog::create([
            'phone_number_id' => $this->phone->id,
            'campaign_id'     => $this->campaign->id,
            'to_number'       => '52' . rand(1000000000, 9999999999),
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => $status,
            'discard_reason'  => $discardReason,
            'sent_at'         => now(),
        ]);
    }

    // ── GET /api/campaigns/{id}/logs ─────────────────────────────────────────

    public function test_logs_devuelve_registros_de_la_campana(): void
    {
        $this->actingAsOperator();

        $this->createLog('sent');
        $this->createLog('discarded', 'cooldown');
        $this->createLog('failed');

        $this->getJson("/api/campaigns/{$this->campaign->id}/logs")
             ->assertStatus(200)
             ->assertJsonPath('status', 'ok')
             ->assertJsonStructure([
                 'status',
                 'stats'    => ['sent', 'failed', 'discarded', 'pending'],
                 'data',
                 'has_more',
             ])
             ->assertJsonCount(3, 'data');
    }

    public function test_logs_devuelve_stats_correctos(): void
    {
        $this->actingAsOperator();

        // La campaña del setUp tiene: total=3, sent=1, failed=1
        // Creamos 1 log discarded para que el controller calcule: failed_real = failed_count - discarded = 1-1 = 0
        $this->createLog('discarded', 'snooze');

        $res = $this->getJson("/api/campaigns/{$this->campaign->id}/logs")
                    ->assertStatus(200)
                    ->json();

        // sent viene de campaign.sent_count = 1
        $this->assertEquals(1, $res['stats']['sent']);
        // discarded = conteo de logs con status=discarded = 1
        $this->assertEquals(1, $res['stats']['discarded']);
        // failed = max(0, campaign.failed_count - discarded) = max(0, 1 - 1) = 0
        $this->assertEquals(0, $res['stats']['failed']);
        // pending = total(3) - sent(1) - failed_count(1) = 1
        $this->assertEquals(1, $res['stats']['pending']);
    }

    public function test_logs_stats_sin_logs_usa_contadores_campaign(): void
    {
        $this->actingAsOperator();

        // Sin ningún log — campaña histórica. failed_count=1, discarded=0 => failed=1
        $res = $this->getJson("/api/campaigns/{$this->campaign->id}/logs")
                    ->assertStatus(200)
                    ->json();

        $this->assertEquals(1, $res['stats']['sent']);
        $this->assertEquals(1, $res['stats']['failed']);   // campaign.failed_count(1) - discarded(0)
        $this->assertEquals(0, $res['stats']['discarded']);
        $this->assertEquals(1, $res['stats']['pending']);  // 3 - 1 - 1
    }

    public function test_logs_devuelve_404_para_campana_inexistente(): void
    {
        $this->actingAsOperator();

        $this->getJson('/api/campaigns/9999/logs')->assertStatus(404);
    }

    public function test_logs_requiere_autenticacion(): void
    {
        $this->getJson("/api/campaigns/{$this->campaign->id}/logs")->assertStatus(401);
    }

    public function test_logs_no_incluye_registros_de_otra_campana(): void
    {
        $this->actingAsOperator();

        $otraCampaña = Campaign::factory()->create(['phone_number_id' => $this->phone->id]);

        // Log de esta campaña
        $this->createLog('sent');

        // Log de otra campaña (no debe aparecer)
        MessageLog::create([
            'phone_number_id' => $this->phone->id,
            'campaign_id'     => $otraCampaña->id,
            'to_number'       => '529000000001',
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => 'sent',
            'sent_at'         => now(),
        ]);

        $this->getJson("/api/campaigns/{$this->campaign->id}/logs")
             ->assertJsonCount(1, 'data');
    }

    // ── POST /api/campaigns/{id}/pause ───────────────────────────────────────

    public function test_pause_cambia_running_a_paused(): void
    {
        $this->actingAsOperator();

        $this->postJson("/api/campaigns/{$this->campaign->id}/pause")
             ->assertStatus(200)
             ->assertJsonPath('status', 'ok')
             ->assertJsonPath('data.status', 'paused');

        $this->assertDatabaseHas('campaigns', [
            'id'     => $this->campaign->id,
            'status' => 'paused',
        ]);
    }

    public function test_pause_rechaza_campana_que_no_esta_running(): void
    {
        $this->actingAsOperator();

        $draft = Campaign::factory()->create([
            'phone_number_id' => $this->phone->id,
            'status'          => 'draft',
        ]);

        $this->postJson("/api/campaigns/{$draft->id}/pause")
             ->assertStatus(422)
             ->assertJsonPath('code', 'INVALID_STATUS');
    }

    public function test_pause_devuelve_404_para_campana_inexistente(): void
    {
        $this->actingAsOperator();

        $this->postJson('/api/campaigns/9999/pause')->assertStatus(404);
    }

    public function test_pause_requiere_autenticacion(): void
    {
        $this->postJson("/api/campaigns/{$this->campaign->id}/pause")->assertStatus(401);
    }

    // ── DELETE /api/campaigns/{id} ───────────────────────────────────────────

    public function test_delete_borra_campana_en_draft(): void
    {
        $this->actingAsOperator();

        $draft = Campaign::factory()->create([
            'phone_number_id' => $this->phone->id,
            'status'          => 'draft',
        ]);

        $this->deleteJson("/api/campaigns/{$draft->id}")
             ->assertStatus(200)
             ->assertJsonPath('status', 'ok');

        $this->assertDatabaseMissing('campaigns', ['id' => $draft->id]);
    }

    public function test_delete_rechaza_campana_no_draft(): void
    {
        $this->actingAsOperator();

        $this->deleteJson("/api/campaigns/{$this->campaign->id}")
             ->assertStatus(422)
             ->assertJsonPath('code', 'INVALID_STATUS');
    }

    public function test_delete_devuelve_404_para_campana_inexistente(): void
    {
        $this->actingAsOperator();

        $this->deleteJson('/api/campaigns/9999')->assertStatus(404);
    }

    public function test_delete_requiere_autenticacion(): void
    {
        $this->deleteJson("/api/campaigns/{$this->campaign->id}")->assertStatus(401);
    }

    // ── Job: discard logs por motivo ─────────────────────────────────────────

    private function makeJobFor(Contact $contact): SendWhatsAppMessage
    {
        return new SendWhatsAppMessage(
            contactId:     $contact->id,
            campaignId:    $this->campaign->id,
            phoneNumberId: $this->phone->id,
            templateName:  'hello_world',
            languageCode:  'en_US',
            bodyVars:      [],
        );
    }

    public function test_job_crea_log_discarded_por_cooldown(): void
    {
        Setting::set('cooldown_days', '30');

        $contact = Contact::factory()->create(['phone' => '521234000001', 'status' => 'active']);

        // Envío hace 10 días — dentro del cooldown de 30
        MessageLog::create([
            'phone_number_id' => $this->phone->id,
            'to_number'       => $contact->phone,
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => 'sent',
            'sent_at'         => now()->subDays(10),
        ]);

        $this->makeJobFor($contact)->handle(app(WhatsAppClient::class), app(TemplateBuilder::class));

        $this->assertDatabaseHas('message_log', [
            'campaign_id'    => $this->campaign->id,
            'to_number'      => $contact->phone,
            'status'         => 'discarded',
            'discard_reason' => 'cooldown',
        ]);
    }

    public function test_job_crea_log_discarded_por_dedup_hoy(): void
    {
        $contact = Contact::factory()->create(['phone' => '521234000002', 'status' => 'active']);

        // Envío hoy
        MessageLog::create([
            'phone_number_id' => $this->phone->id,
            'to_number'       => $contact->phone,
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => 'sent',
            'sent_at'         => now(),
        ]);

        $this->makeJobFor($contact)->handle(app(WhatsAppClient::class), app(TemplateBuilder::class));

        $this->assertDatabaseHas('message_log', [
            'campaign_id'    => $this->campaign->id,
            'to_number'      => $contact->phone,
            'status'         => 'discarded',
            'discard_reason' => 'dedup_today',
        ]);
    }

    // ── Auto-complete ────────────────────────────────────────────────────────

    public function test_campana_se_completa_al_procesar_todos_los_contactos(): void
    {
        // total_contacts=1, sent_count=0, failed_count=0
        $campaign = Campaign::factory()->create([
            'phone_number_id' => $this->phone->id,
            'status'          => 'running',
            'total_contacts'  => 1,
            'sent_count'      => 0,
            'failed_count'    => 0,
        ]);
        $contact = Contact::factory()->create(['phone' => '521234099999', 'status' => 'active']);

        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldReceive('post')->andReturn([
                'ok'   => true,
                'body' => ['messages' => [['id' => 'wamid.autocomplete_test']]],
            ]);
        });

        $job = new SendWhatsAppMessage(
            contactId:     $contact->id,
            campaignId:    $campaign->id,
            phoneNumberId: $this->phone->id,
            templateName:  'hello_world',
            languageCode:  'en_US',
            bodyVars:      [],
        );

        $job->handle(app(WhatsAppClient::class), app(TemplateBuilder::class));

        $campaign->refresh();
        $this->assertEquals('completed', $campaign->status);
        $this->assertNotNull($campaign->completed_at);
    }

    public function test_campana_pausada_no_env_ia_y_no_completa(): void
    {
        $campaign = Campaign::factory()->create([
            'phone_number_id' => $this->phone->id,
            'status'          => 'paused',
            'total_contacts'  => 1,
            'sent_count'      => 0,
            'failed_count'    => 0,
        ]);
        $contact = Contact::factory()->create(['phone' => '521234088888', 'status' => 'active']);

        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldReceive('post')->never();
        });

        $job = new SendWhatsAppMessage(
            contactId:     $contact->id,
            campaignId:    $campaign->id,
            phoneNumberId: $this->phone->id,
            templateName:  'hello_world',
            languageCode:  'en_US',
            bodyVars:      [],
        );

        $job->handle(app(WhatsAppClient::class), app(TemplateBuilder::class));

        $campaign->refresh();
        $this->assertEquals('paused', $campaign->status);
        $this->assertEquals(0, $campaign->sent_count);
    }

    // ── POST /api/campaigns/{id}/retry-pending ────────────────────────────────

    public function test_retry_pending_requiere_autenticacion(): void
    {
        $this->postJson("/api/campaigns/{$this->campaign->id}/retry-pending")
             ->assertStatus(401);
    }

    public function test_retry_pending_falla_si_campana_no_existe(): void
    {
        $this->actingAsOperator()
             ->postJson('/api/campaigns/9999/retry-pending')
             ->assertStatus(404);
    }

    public function test_retry_pending_falla_si_campana_no_esta_running(): void
    {
        $campaign = Campaign::factory()->create([
            'status'         => 'completed',
            'total_contacts' => 10,
            'sent_count'     => 10,
            'failed_count'   => 0,
        ]);

        $this->actingAsOperator()
             ->postJson("/api/campaigns/{$campaign->id}/retry-pending")
             ->assertStatus(422)
             ->assertJsonPath('code', 'INVALID_STATUS');
    }

    public function test_retry_pending_falla_si_no_hay_pendientes(): void
    {
        // sent + failed == total → pending = 0
        $campaign = Campaign::factory()->create([
            'status'         => 'running',
            'total_contacts' => 2,
            'sent_count'     => 1,
            'failed_count'   => 1,
        ]);

        $this->actingAsOperator()
             ->postJson("/api/campaigns/{$campaign->id}/retry-pending")
             ->assertStatus(422)
             ->assertJsonPath('code', 'NO_PENDING');
    }

    public function test_retry_pending_despacha_solo_contactos_sin_log(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        // Campaña con 3 total, 1 procesado, 2 "perdidos" (pending=2)
        $campaign = Campaign::factory()->create([
            'status'         => 'running',
            'total_contacts' => 3,
            'sent_count'     => 1,
            'failed_count'   => 0,
        ]);

        // Contacto que YA tiene log — no debe re-despacharse
        $processed = Contact::factory()->create(['status' => 'active', 'phone' => '529231311001']);
        MessageLog::create([
            'phone_number_id' => $this->phone->id,
            'campaign_id'     => $campaign->id,
            'to_number'       => $processed->phone,
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => 'sent',
            'sent_at'         => now(),
        ]);

        // Dos contactos sin log — deben re-despacharse
        Contact::factory()->create(['status' => 'active', 'phone' => '529231311002']);
        Contact::factory()->create(['status' => 'active', 'phone' => '529231311003']);

        $res = $this->actingAsOperator()
                    ->postJson("/api/campaigns/{$campaign->id}/retry-pending")
                    ->assertStatus(200)
                    ->assertJsonPath('data.jobs_dispatched', 2);

        \Illuminate\Support\Facades\Queue::assertPushed(
            \App\Jobs\SendWhatsAppMessage::class,
            2
        );

        // total_contacts debe ajustarse: sent(1) + failed(0) + nuevos(2) = 3
        $campaign->refresh();
        $this->assertEquals(3, $campaign->total_contacts);
    }

    public function test_retry_pending_stats_incluye_resumes_at_cuando_hay_pendientes(): void
    {
        // Campaña con pending > 0
        $campaign = Campaign::factory()->create([
            'status'         => 'running',
            'total_contacts' => 5,
            'sent_count'     => 2,
            'failed_count'   => 1,
        ]);

        $res = $this->actingAsOperator()
                    ->getJson("/api/campaigns/{$campaign->id}/logs")
                    ->assertStatus(200);

        // pending = 2 → resumes_at debe ser una cadena no nula
        $resumesAt = $res->json('stats.resumes_at');
        $this->assertNotNull($resumesAt);
        $this->assertIsString($resumesAt);
    }

    public function test_retry_pending_stats_resumes_at_es_null_cuando_no_hay_pendientes(): void
    {
        // Campaña sin pendientes
        $campaign = Campaign::factory()->create([
            'status'         => 'completed',
            'total_contacts' => 2,
            'sent_count'     => 1,
            'failed_count'   => 1,
        ]);

        $res = $this->actingAsOperator()
                    ->getJson("/api/campaigns/{$campaign->id}/logs")
                    ->assertStatus(200);

        $this->assertNull($res->json('stats.resumes_at'));
    }
}
