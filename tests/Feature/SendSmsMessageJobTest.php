<?php

namespace Tests\Feature;

use App\Jobs\SendSmsMessage;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\Setting;
use App\Services\Sms\SmsGatewayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SendSmsMessageJobTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contact = Contact::factory()->create([
            'phone'  => '529991234567',
            'status' => 'active',
        ]);
        $this->campaign = Campaign::factory()->create([
            'channel'         => 'sms',
            'sms_body'        => 'Hola, soy Prestamaz. STOP para baja',
            'template_name'   => null,
            'phone_number_id' => null,
            'status'          => 'running',
            'total_contacts'  => 1,
        ]);
    }

    private function mockOkClient(): void
    {
        $this->mock(SmsGatewayClient::class, function ($mock) {
            $mock->shouldReceive('send')->andReturn([
                'ok' => true, 'status' => 202, 'message_id' => 'SM-1', 'error' => null,
            ]);
        });
    }

    private function makeJob(): SendSmsMessage
    {
        return new SendSmsMessage($this->contact->id, $this->campaign->id, $this->campaign->sms_body);
    }

    public function test_envio_exitoso_crea_log_sms_y_incrementa_sent(): void
    {
        $this->mockOkClient();

        $this->makeJob()->handle(app(SmsGatewayClient::class));

        $this->assertDatabaseHas('message_log', [
            'to_number' => '529991234567',
            'channel'   => 'sms',
            'status'    => 'sent',
            'wa_message_id' => 'SM-1',
        ]);
        $this->assertSame(1, $this->campaign->fresh()->sent_count);
    }

    public function test_optout_whatsapp_bloquea_sms_cross_channel(): void
    {
        $this->contact->update(['status' => 'opted_out']);

        $this->makeJob()->handle(app(SmsGatewayClient::class));

        $this->assertDatabaseHas('message_log', [
            'channel'        => 'sms',
            'status'         => 'discarded',
            'discard_reason' => 'opted_out',
        ]);
        $this->assertSame(1, $this->campaign->fresh()->failed_count);
    }

    public function test_sms_blocked_descarta(): void
    {
        $this->contact->update(['sms_blocked' => true]);

        $this->makeJob()->handle(app(SmsGatewayClient::class));

        $this->assertDatabaseHas('message_log', [
            'channel'        => 'sms',
            'status'         => 'discarded',
            'discard_reason' => 'sms_blocked',
        ]);
    }

    public function test_dedup_cross_channel_descarta_si_recibio_wa_hoy(): void
    {
        // Un mensaje WhatsApp enviado hoy al mismo número debe frenar el SMS.
        MessageLog::create([
            'channel'     => 'whatsapp',
            'to_number'   => '529991234567',
            'status'      => 'sent',
            'sent_at'     => now(),
        ]);

        $this->makeJob()->handle(app(SmsGatewayClient::class));

        $this->assertDatabaseHas('message_log', [
            'channel'        => 'sms',
            'status'         => 'discarded',
            'discard_reason' => 'dedup_today',
        ]);
    }

    public function test_cooldown_cross_channel_descarta(): void
    {
        Setting::set('cooldown_days', 30);

        MessageLog::create([
            'channel'   => 'whatsapp',
            'to_number' => '529991234567',
            'status'    => 'sent',
            'sent_at'   => now()->subDays(5),
        ]);

        $this->makeJob()->handle(app(SmsGatewayClient::class));

        $this->assertDatabaseHas('message_log', [
            'channel'        => 'sms',
            'status'         => 'discarded',
            'discard_reason' => 'cooldown',
        ]);
    }
}
