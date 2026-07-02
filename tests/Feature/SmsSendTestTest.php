<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsSendTestTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_envia_sms_de_prueba(): void
    {
        Http::fake(['*/messages' => Http::response(['id' => 'SM-test'], 202)]);
        $this->actingAsAdmin();

        $this->postJson('/api/sms/send-test', [
            'to'   => '5299912345',
            'body' => 'Mensaje de prueba',
        ])
        ->assertStatus(200)
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('data.sms_status', 'sent')
        ->assertJsonPath('data.message_id', 'SM-test');

        $this->assertDatabaseHas('message_log', [
            'channel'       => 'sms',
            'to_number'     => '525299912345',
            'wa_message_id' => 'SM-test',
            'campaign_id'   => null,
        ]);
    }

    public function test_operador_no_puede_enviar_prueba(): void
    {
        $this->actingAsOperator();

        $this->postJson('/api/sms/send-test', [
            'to'   => '5299912345',
            'body' => 'x',
        ])->assertStatus(403);
    }

    public function test_numero_invalido_es_rechazado(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/sms/send-test', [
            'to'   => '123',
            'body' => 'x',
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_NUMBER');
    }
}
