<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\MessageLog;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorSmsWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function sentSms(?string $when = null): void
    {
        MessageLog::create([
            'channel'   => 'sms',
            'to_number' => '529991234567',
            'sms_body'  => 'hola',
            'status'    => 'sent',
            'sent_at'   => $when ?? now(),
        ]);
    }

    public function test_no_alerta_si_no_hubo_envios_recientes(): void
    {
        // Webhook silencioso pero sin actividad de envío: no alerta.
        $this->artisan('sms:monitor-webhook')->assertExitCode(0);

        $this->assertDatabaseCount('app_notifications', 0);
    }

    public function test_alerta_cuando_envia_pero_webhook_silencioso(): void
    {
        $this->sentSms();
        // sin sms_webhook_last_hit_at => nunca ha llegado nada

        $this->artisan('sms:monitor-webhook')->assertExitCode(0);

        $this->assertDatabaseHas('app_notifications', ['type' => 'sms_webhook_down']);
    }

    public function test_alerta_por_rechazo_de_firma(): void
    {
        $this->sentSms();
        Setting::set('sms_webhook_last_hit_at', now()->toDateTimeString());
        Setting::set('sms_webhook_last_rejected_at', now()->toDateTimeString());

        $this->artisan('sms:monitor-webhook')->assertExitCode(0);

        $notif = AppNotification::where('type', 'sms_webhook_down')->first();
        $this->assertNotNull($notif);
        $this->assertStringContainsString('firma', $notif->body);
    }

    public function test_no_alerta_si_webhook_reciente_ok(): void
    {
        $this->sentSms();
        Setting::set('sms_webhook_last_hit_at', now()->toDateTimeString());
        Setting::set('sms_webhook_last_at', now()->toDateTimeString());

        $this->artisan('sms:monitor-webhook')->assertExitCode(0);

        $this->assertDatabaseCount('app_notifications', 0);
    }

    public function test_no_duplica_alerta_pendiente(): void
    {
        $this->sentSms();

        $this->artisan('sms:monitor-webhook');
        $this->artisan('sms:monitor-webhook');

        $this->assertSame(1, AppNotification::where('type', 'sms_webhook_down')->count());
    }

    public function test_evento_ok_marca_leida_la_alerta(): void
    {
        AppNotification::create(['type' => 'sms_webhook_down', 'title' => 'x', 'body' => 'y']);

        MessageLog::create([
            'channel' => 'sms', 'to_number' => '529991234567', 'sms_body' => 't',
            'wa_message_id' => 'SM-ok', 'status' => 'sent', 'sent_at' => now(),
        ]);

        $this->postJson('/api/sms/webhook', [
            'event'   => 'sms:delivered',
            'payload' => ['messageId' => 'SM-ok'],
        ])->assertStatus(200);

        $this->assertNotNull(AppNotification::where('type', 'sms_webhook_down')->first()->read_at);
    }
}
