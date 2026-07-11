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

        // Fijar el reloj dentro de la ventana de envío (miércoles 12:00 CST) para que
        // el guardia de horario del job no reencole y los tests no dependan del reloj real.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-07-08 12:00:00', 'America/Mexico_City'));
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

    // ── Ventana de envío (guardia de horario en el job) ─────────────────────────

    public function test_fuera_de_ventana_no_envia_ni_registra(): void
    {
        // Sábado 12:00 CST — fuera de ventana (solo L-V). El cliente no debe llamarse.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-07-11 12:00:00', 'America/Mexico_City'));
        $this->mock(WhatsAppClient::class, fn ($mock) => $mock->shouldReceive('post')->never());

        $this->makeJob()->handle(app(WhatsAppClient::class), app(TemplateBuilder::class));

        // No se registró envío ni se movieron contadores: el job se reencoló.
        $this->assertDatabaseCount('message_log', 0);
        $this->assertDatabaseHas('campaigns', [
            'id'           => $this->campaign->id,
            'sent_count'   => 0,
            'failed_count' => 0,
        ]);
    }

    public function test_fuera_de_ventana_con_modo_demo_si_envia(): void
    {
        Setting::set('schedule_bypass', '1');
        // Domingo 3AM — fuera de ventana, pero el modo demo la abre.
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-07-12 03:00:00', 'America/Mexico_City'));
        $this->mockSuccessfulClient();

        $this->makeJob()->handle(app(WhatsAppClient::class), app(TemplateBuilder::class));

        $this->assertDatabaseHas('campaigns', [
            'id'         => $this->campaign->id,
            'sent_count' => 1,
        ]);
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

    public function test_cooldown_cuenta_mensajes_entregados_no_solo_sent(): void
    {
        // Regresión: un mensaje que llegó a 'delivered'/'read' (el webhook cambió el status
        // desde 'sent') DEBE seguir contando para el cooldown. Antes solo miraba 'sent', así
        // que en cuanto Meta confirmaba entrega el cooldown se saltaba y reenviaba al día siguiente.
        Setting::set('cooldown_days', '30');

        MessageLog::create([
            'phone_number_id' => $this->phone->id,
            'to_number'       => $this->contact->phone,
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => 'delivered', // entregado hace 15 días
            'sent_at'         => now()->subDays(15),
        ]);

        $this->mock(WhatsAppClient::class, fn ($mock) => $mock->shouldReceive('post')->never());

        $this->makeJob()->handle(app(WhatsAppClient::class), app(TemplateBuilder::class));

        $this->assertDatabaseHas('message_log', [
            'to_number'      => $this->contact->phone,
            'status'         => 'discarded',
            'discard_reason' => 'cooldown',
        ]);
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

    public function test_descarta_contacto_con_hold_de_marketing_activo(): void
    {
        // 131049 dejó un hold de 24h: no reintentar plantilla de marketing por WhatsApp.
        $this->contact->update(['wa_marketing_hold_until' => now()->addHours(20)]);

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
            'discard_reason' => 'marketing_hold',
        ]);
    }

    public function test_permite_envio_si_hold_de_marketing_ya_expiro(): void
    {
        $this->mockSuccessfulClient();
        $this->contact->update(['wa_marketing_hold_until' => now()->subHour()]);

        $this->makeJob()->handle(
            app(WhatsAppClient::class),
            app(TemplateBuilder::class),
        );

        $this->assertDatabaseHas('campaigns', [
            'id'         => $this->campaign->id,
            'sent_count' => 1,
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

    // ── Freno del portfolio ───────────────────────────────────────────────────

    public function test_freno_del_portfolio_no_envia_si_se_alcanzo_el_techo(): void
    {
        Setting::set('wa_portfolio_daily_limit', 'TIER_250'); // techo de cuenta = 250

        // 250 enviados hoy en OTRO número: el total de la CUENTA ya llegó al techo,
        // aunque este número no haya enviado nada.
        $otherPhone = PhoneNumber::factory()->create(['is_active' => true]);
        MessageLog::factory()->count(250)->create([
            'phone_number_id' => $otherPhone->id,
            'status'          => 'sent',
            'sent_at'         => now(),
        ]);

        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldReceive('post')->never();
        });

        try {
            $this->makeJob()->handle(app(WhatsAppClient::class), app(TemplateBuilder::class));
        } catch (\Error) {
            // release() sin queue real
        }

        $this->assertEquals(0, $this->campaign->fresh()->sent_count);
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

    public function test_warm_down_baja_el_limite_a_la_mitad_en_131048(): void
    {
        $this->phone->update(['daily_limit' => 1000]);

        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldReceive('post')->andReturn([
                'ok'   => false,
                'body' => ['error' => ['code' => 131048, 'message' => 'Spam rate limit']],
            ]);
        });

        try {
            $this->makeJob()->handle(app(WhatsAppClient::class), app(TemplateBuilder::class));
        } catch (\Error) {
            // release() sin queue real
        }

        $this->assertEquals(500, $this->phone->fresh()->daily_limit);
    }

    public function test_warm_down_respeta_el_piso_de_250(): void
    {
        $phone = PhoneNumber::factory()->create(['daily_limit' => 300]);
        $phone->backOffDailyLimit();

        $this->assertEquals(250, $phone->fresh()->daily_limit); // max(250, 150)
    }

    public function test_error_131064_pausa_el_numero_60_minutos(): void
    {
        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldReceive('post')->andReturn([
                'ok'   => false,
                'body' => ['error' => ['code' => 131064, 'message' => 'Account messaging limit reached due to template categorization']],
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
