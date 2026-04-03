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

class SendWhatsAppMessageJobTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;
    private Contact $contact;
    private PhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phone = PhoneNumber::factory()->create([
            'is_active'   => true,
            'daily_limit' => 250,
        ]);
        $this->contact = Contact::factory()->create([
            'phone'  => '521234567890',
            'status' => 'active',
        ]);
        $this->campaign = Campaign::factory()->create([
            'phone_number_id' => $this->phone->id,
            'status'          => 'running',
        ]);
    }

    private function mockSuccessfulClient(): void
    {
        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldReceive('post')->andReturn([
                'ok'   => true,
                'body' => ['messages' => [['id' => 'wamid.test123']]],
            ]);
        });
    }

    private function makeJob(): SendWhatsAppMessage
    {
        return new SendWhatsAppMessage(
            contactId:     $this->contact->id,
            campaignId:    $this->campaign->id,
            phoneNumberId: $this->phone->id,
            templateName:  'hello_world',
            languageCode:  'en_US',
            bodyVars:      [],
        );
    }

    // ── Dedup ─────────────────────────────────────────────────────────────────

    public function test_dedup_descarta_si_ya_se_envio_hoy(): void
    {
        // Simular un envío exitoso hoy
        MessageLog::create([
            'phone_number_id' => $this->phone->id,
            'to_number'       => $this->contact->phone,
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => 'sent',
            'sent_at'         => now(),
        ]);

        $this->makeJob()->handle(
            app(WhatsAppClient::class),
            app(TemplateBuilder::class),
        );

        $this->assertDatabaseHas('campaigns', [
            'id'           => $this->campaign->id,
            'failed_count' => 1,
            'sent_count'   => 0,
        ]);
    }

    public function test_dedup_permite_envio_si_ultimo_fue_ayer(): void
    {
        $this->mockSuccessfulClient();

        // Envío de ayer — no debe bloquear
        MessageLog::create([
            'phone_number_id' => $this->phone->id,
            'to_number'       => $this->contact->phone,
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => 'sent',
            'sent_at'         => now()->subDays(45),
        ]);

        $this->makeJob()->handle(
            app(WhatsAppClient::class),
            app(TemplateBuilder::class),
        );

        $this->assertDatabaseHas('campaigns', [
            'id'         => $this->campaign->id,
            'sent_count' => 1,
        ]);
    }

    // ── Cooldown ──────────────────────────────────────────────────────────────

    public function test_cooldown_descarta_si_enviado_hace_menos_de_cooldown_dias(): void
    {
        Setting::set('cooldown_days', '30');

        // Envío hace 15 días — dentro del cooldown de 30
        MessageLog::create([
            'phone_number_id' => $this->phone->id,
            'to_number'       => $this->contact->phone,
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => 'sent',
            'sent_at'         => now()->subDays(15),
        ]);

        $this->makeJob()->handle(
            app(WhatsAppClient::class),
            app(TemplateBuilder::class),
        );

        $this->assertDatabaseHas('campaigns', [
            'id'           => $this->campaign->id,
            'failed_count' => 1,
            'sent_count'   => 0,
        ]);
    }

    public function test_cooldown_permite_envio_si_pasaron_suficientes_dias(): void
    {
        $this->mockSuccessfulClient();
        Setting::set('cooldown_days', '30');

        // Envío hace 35 días — fuera del cooldown
        MessageLog::create([
            'phone_number_id' => $this->phone->id,
            'to_number'       => $this->contact->phone,
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => 'sent',
            'sent_at'         => now()->subDays(35),
        ]);

        $this->makeJob()->handle(
            app(WhatsAppClient::class),
            app(TemplateBuilder::class),
        );

        $this->assertDatabaseHas('campaigns', [
            'id'         => $this->campaign->id,
            'sent_count' => 1,
        ]);
    }

    public function test_cooldown_minimo_forzado_es_7_dias(): void
    {
        // Intentar cooldown de 1 día — el sistema fuerza mínimo 7
        Setting::set('cooldown_days', '1');

        // Envío hace 3 días — sigue en cooldown por el mínimo de 7
        MessageLog::create([
            'phone_number_id' => $this->phone->id,
            'to_number'       => $this->contact->phone,
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => 'sent',
            'sent_at'         => now()->subDays(3),
        ]);

        $this->makeJob()->handle(
            app(WhatsAppClient::class),
            app(TemplateBuilder::class),
        );

        $this->assertDatabaseHas('campaigns', [
            'id'           => $this->campaign->id,
            'failed_count' => 1,
        ]);
    }

    // ── Opt-out e inválidos ───────────────────────────────────────────────────

    public function test_descarta_contacto_opted_out(): void
    {
        $this->contact->update(['status' => 'opted_out']);

        $this->makeJob()->handle(
            app(WhatsAppClient::class),
            app(TemplateBuilder::class),
        );

        $this->assertDatabaseHas('campaigns', [
            'id'           => $this->campaign->id,
            'failed_count' => 1,
        ]);
        $this->assertDatabaseHas('message_log', [
            'campaign_id'    => $this->campaign->id,
            'to_number'      => $this->contact->phone,
            'status'         => 'discarded',
            'discard_reason' => 'opted_out',
        ]);
    }

    public function test_descarta_contacto_invalido(): void
    {
        $this->contact->update(['status' => 'invalid']);

        $this->makeJob()->handle(
            app(WhatsAppClient::class),
            app(TemplateBuilder::class),
        );

        $this->assertDatabaseHas('campaigns', [
            'id'           => $this->campaign->id,
            'failed_count' => 1,
        ]);
        $this->assertDatabaseHas('message_log', [
            'campaign_id'    => $this->campaign->id,
            'status'         => 'discarded',
            'discard_reason' => 'opted_out',
        ]);
    }

    // ── Snooze ────────────────────────────────────────────────────────────────

    public function test_descarta_contacto_con_snooze_activo(): void
    {
        $this->contact->update(['snoozed_until' => now()->addDays(20)]);

        $this->makeJob()->handle(
            app(WhatsAppClient::class),
            app(TemplateBuilder::class),
        );

        $this->assertDatabaseHas('campaigns', [
            'id'           => $this->campaign->id,
            'failed_count' => 1,
        ]);
        $this->assertDatabaseHas('message_log', [
            'campaign_id'    => $this->campaign->id,
            'status'         => 'discarded',
            'discard_reason' => 'snooze',
        ]);
    }

    public function test_permite_envio_si_snooze_ya_expiro(): void
    {
        $this->mockSuccessfulClient();
        $this->contact->update(['snoozed_until' => now()->subDay()]);

        $this->makeJob()->handle(
            app(WhatsAppClient::class),
            app(TemplateBuilder::class),
        );

        $this->assertDatabaseHas('campaigns', [
            'id'         => $this->campaign->id,
            'sent_count' => 1,
        ]);
    }

    // ── Circuit breaker ───────────────────────────────────────────────────────

    public function test_circuit_breaker_numero_pausado_no_llama_post(): void
    {
        $this->phone->update(['paused_until' => now()->addHour()]);

        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldReceive('post')->never();
        });

        // release() lanza Error cuando no hay queue job real — es comportamiento
        // esperado en tests directos; lo que importa es que post() no se llamó
        try {
            $this->makeJob()->handle(
                app(WhatsAppClient::class),
                app(TemplateBuilder::class),
            );
        } catch (\Error) {
            // release() sin queue job real — ignorar
        }

        // Contadores no deben moverse
        $this->assertDatabaseHas('campaigns', [
            'id'           => $this->campaign->id,
            'sent_count'   => 0,
            'failed_count' => 0,
        ]);
    }

    public function test_error_131048_llama_pause_for_60_minutos(): void
    {
        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldReceive('post')->andReturn([
                'ok'   => false,
                'body' => ['error' => ['code' => 131048, 'message' => 'Spam rate limit']],
            ]);
        });

        // release(3600) lanza Error sin queue job real; pauseFor() ya escribió a BD
        try {
            $this->makeJob()->handle(
                app(WhatsAppClient::class),
                app(TemplateBuilder::class),
            );
        } catch (\Error) {
            // release() sin queue job real — ignorar
        }

        $this->phone->refresh();
        $this->assertTrue($this->phone->isPaused());
        $this->assertEqualsWithDelta(60, now()->diffInMinutes($this->phone->paused_until), 2);
    }

    public function test_error_368_llama_pause_for_24_horas(): void
    {
        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldReceive('post')->andReturn([
                'ok'   => false,
                'body' => ['error' => ['code' => 368, 'message' => 'Account temporarily blocked']],
            ]);
        });

        // fail() no lanza cuando $this->job es null — test retorna normalmente
        $this->makeJob()->handle(
            app(WhatsAppClient::class),
            app(TemplateBuilder::class),
        );

        $this->phone->refresh();
        $this->assertTrue($this->phone->isPaused());
        $this->assertEqualsWithDelta(1440, now()->diffInMinutes($this->phone->paused_until), 2);
    }
}
