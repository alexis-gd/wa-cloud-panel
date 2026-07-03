<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageLog;
use App\Services\Sms\SmsGatewayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileSmsStatusTest extends TestCase
{
    use RefreshDatabase;

    private function smsSent(string $id, string $to = '529991234567', ?string $sentAt = null): MessageLog
    {
        return MessageLog::create([
            'channel'       => 'sms',
            'to_number'     => $to,
            'sms_body'      => 'hola',
            'wa_message_id' => $id,
            'status'        => 'sent',
            'sent_at'       => $sentAt ?? now()->subMinutes(10),
        ]);
    }

    private function fakeState(string $state): void
    {
        $this->mock(SmsGatewayClient::class, function ($mock) use ($state) {
            $mock->shouldReceive('getState')->andReturn(['ok' => true, 'state' => $state, 'error' => null]);
        });
    }

    public function test_delivered_actualiza_status(): void
    {
        $log = $this->smsSent('SM-1');
        $this->fakeState('Delivered');

        $this->artisan('sms:reconcile-status')->assertExitCode(0);

        $this->assertSame('delivered', $log->fresh()->status);
    }

    public function test_failed_actualiza_status_y_registra_rebote(): void
    {
        $contact = Contact::factory()->create(['phone' => '529991234567', 'status' => 'active']);
        $log = $this->smsSent('SM-2');
        $this->fakeState('Failed');

        $this->artisan('sms:reconcile-status')->assertExitCode(0);

        $this->assertSame('failed', $log->fresh()->status);
        $this->assertSame(1, $contact->fresh()->sms_bounce_count);
    }

    public function test_sent_muy_reciente_no_se_toca(): void
    {
        // Menos de 3 min de vida: se le da chance al webhook primero.
        $log = $this->smsSent('SM-3', sentAt: now()->subMinute());
        $this->fakeState('Delivered');

        $this->artisan('sms:reconcile-status')->assertExitCode(0);

        $this->assertSame('sent', $log->fresh()->status);
    }

    public function test_estado_intermedio_no_cambia(): void
    {
        $log = $this->smsSent('SM-4');
        $this->fakeState('Sent');

        $this->artisan('sms:reconcile-status')->assertExitCode(0);

        $this->assertSame('sent', $log->fresh()->status);
    }
}
