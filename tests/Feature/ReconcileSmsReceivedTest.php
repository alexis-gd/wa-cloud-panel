<?php

namespace Tests\Feature;

use App\Services\Sms\SmsGatewayClient;
use Tests\TestCase;

class ReconcileSmsReceivedTest extends TestCase
{
    public function test_solicita_reexport_de_entrantes(): void
    {
        $this->mock(SmsGatewayClient::class, function ($mock) {
            $mock->shouldReceive('requestInboxExport')
                ->once()
                ->andReturn(['ok' => true, 'status' => 202, 'error' => null]);
        });

        $this->artisan('sms:reconcile-received')->assertExitCode(0);
    }

    public function test_usa_la_ventana_de_horas_indicada(): void
    {
        $this->mock(SmsGatewayClient::class, function ($mock) {
            $mock->shouldReceive('requestInboxExport')
                ->once()
                ->withArgs(function ($since, $until, $deviceId) {
                    // since ~= now-6h; margen amplio para no depender del reloj exacto.
                    return \Illuminate\Support\Carbon::parse($since)->diffInMinutes(now()) >= 350
                        && \Illuminate\Support\Carbon::parse($since)->diffInMinutes(now()) <= 370;
                })
                ->andReturn(['ok' => true, 'status' => 202, 'error' => null]);
        });

        $this->artisan('sms:reconcile-received --hours=6')->assertExitCode(0);
    }

    public function test_falla_si_el_gateway_rechaza(): void
    {
        $this->mock(SmsGatewayClient::class, function ($mock) {
            $mock->shouldReceive('requestInboxExport')
                ->once()
                ->andReturn(['ok' => false, 'status' => 500, 'error' => 'boom']);
        });

        $this->artisan('sms:reconcile-received')->assertExitCode(1);
    }
}
