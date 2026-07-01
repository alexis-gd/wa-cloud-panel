<?php

namespace Tests\Unit;

use App\Services\Sms\SmsGatewayClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsGatewayClientTest extends TestCase
{
    public function test_send_devuelve_ok_y_message_id(): void
    {
        Http::fake([
            '*/message' => Http::response(['id' => 'SM-abc123', 'state' => 'Pending'], 202),
        ]);

        $result = (new SmsGatewayClient())->send('529991234567', 'Hola, soy Prestamaz. STOP para baja');

        $this->assertTrue($result['ok']);
        $this->assertSame('SM-abc123', $result['message_id']);
        $this->assertNull($result['error']);
    }

    public function test_send_envia_texto_y_numero_al_gateway(): void
    {
        Http::fake(['*/message' => Http::response(['id' => 'SM-1'], 202)]);

        (new SmsGatewayClient())->send('529991234567', 'contenido');

        Http::assertSent(function ($request) {
            return str_ends_with($request->url(), '/message')
                && $request['message'] === 'contenido'
                && $request['phoneNumbers'] === ['529991234567'];
        });
    }

    public function test_send_marca_error_si_el_gateway_falla(): void
    {
        Http::fake([
            '*/message' => Http::response(['message' => 'unauthorized'], 401),
        ]);

        $result = (new SmsGatewayClient())->send('529991234567', 'x');

        $this->assertFalse($result['ok']);
        $this->assertNull($result['message_id']);
        $this->assertSame(401, $result['status']);
    }
}
