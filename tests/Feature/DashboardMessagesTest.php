<?php

namespace Tests\Feature;

use App\Models\MessageLog;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardMessagesTest extends TestCase
{
    use RefreshDatabase;

    private PhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->phone = PhoneNumber::factory()->create(['is_active' => true]);
    }

    private function createLogs(array $statuses): void
    {
        foreach ($statuses as $status) {
            MessageLog::factory()->create([
                'phone_number_id' => $this->phone->id,
                'status'          => $status,
            ]);
        }
    }

    public function test_messages_endpoint_returns_paginated_logs(): void
    {
        $this->actingAsAdmin();
        $this->createLogs(['sent', 'delivered', 'read', 'failed']);

        $res = $this->getJson('/api/dashboard/messages')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure(['data', 'meta' => ['total', 'page', 'per_page', 'pages']]);

        $this->assertEquals(4, $res->json('meta.total'));
    }

    public function test_messages_endpoint_filters_by_status(): void
    {
        $this->actingAsAdmin();
        $this->createLogs(['sent', 'sent', 'failed']);

        $res = $this->getJson('/api/dashboard/messages?status=sent')
            ->assertOk();

        $this->assertCount(2, $res->json('data'));
    }

    public function test_messages_endpoint_respects_per_page(): void
    {
        $this->actingAsAdmin();
        $this->createLogs(array_fill(0, 25, 'sent'));

        $res = $this->getJson('/api/dashboard/messages?per_page=10')
            ->assertOk();

        $this->assertCount(10, $res->json('data'));
        $this->assertEquals(25, $res->json('meta.total'));
        $this->assertEquals(3, $res->json('meta.pages'));
    }

    public function test_operator_can_access_messages_endpoint(): void
    {
        $this->actingAsOperator();

        $this->getJson('/api/dashboard/messages')->assertOk();
    }

    public function test_messages_endpoint_expone_channel_para_iconos(): void
    {
        // El icono de canal (WhatsApp/SMS) en "Últimos mensajes" depende de este campo.
        $this->actingAsAdmin();
        MessageLog::factory()->create(['phone_number_id' => $this->phone->id, 'status' => 'sent', 'channel' => 'whatsapp']);
        MessageLog::create(['channel' => 'sms', 'to_number' => '529991234567', 'sms_body' => 'hola', 'status' => 'sent', 'sent_at' => now()]);

        $channels = collect($this->getJson('/api/dashboard/messages')->assertOk()->json('data'))
            ->pluck('channel')->all();

        $this->assertContains('whatsapp', $channels);
        $this->assertContains('sms', $channels);
    }

    public function test_stats_endpoint_no_longer_returns_recent_messages(): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson('/api/dashboard/stats')->assertOk();

        // El endpoint de stats ya no debe incluir recent_messages
        $this->assertArrayNotHasKey('recent_messages', $res->json('data'));
    }

    // ── Motivo del fallo: la tabla decia solo "failed" y el operador no sabia por que ──

    public function test_messages_endpoint_returns_translated_reason_for_delivery_error(): void
    {
        $this->actingAsAdmin();

        MessageLog::factory()->create([
            'phone_number_id'      => $this->phone->id,
            'channel'              => 'whatsapp',
            'status'               => 'failed',
            'delivery_error_code'  => 131049,
            'delivery_error_title' => 'Message undeliverable',
        ]);

        $res = $this->getJson('/api/dashboard/messages')->assertOk();

        $this->assertStringContainsString('límite de mensajes de marketing', $res->json('data.0.reason'));
        $this->assertStringContainsString('Meta respondió:', $res->json('data.0.reason_detail'));
        $this->assertStringContainsString('(código 131049)', $res->json('data.0.reason_detail'));
    }

    public function test_messages_endpoint_does_not_blame_meta_for_an_sms_failure(): void
    {
        $this->actingAsAdmin();

        MessageLog::factory()->create([
            'phone_number_id' => $this->phone->id,
            'channel'         => 'sms',
            'status'          => 'failed',
            'error_message'   => 'El gateway reportó el envío como fallido (sin detalle)',
        ]);

        $detalle = $this->getJson('/api/dashboard/messages')->assertOk()->json('data.0.reason_detail');

        $this->assertStringContainsString('gateway de SMS', $detalle);
        $this->assertStringNotContainsString('Meta', $detalle);
    }

    public function test_messages_endpoint_leaves_reason_empty_when_the_message_went_through(): void
    {
        $this->actingAsAdmin();
        $this->createLogs(['delivered']);

        $this->assertNull($this->getJson('/api/dashboard/messages')->assertOk()->json('data.0.reason'));
    }

    // ── Busqueda por numero ──

    public function test_messages_endpoint_finds_a_number_typed_with_separators(): void
    {
        $this->actingAsAdmin();

        MessageLog::factory()->create(['phone_number_id' => $this->phone->id, 'to_number' => '529231311146']);
        MessageLog::factory()->create(['phone_number_id' => $this->phone->id, 'to_number' => '526691273636']);

        $res = $this->getJson('/api/dashboard/messages?search=' . urlencode('923 131 1146'))->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('529231311146', $res->json('data.0.to_number'));
    }

    public function test_messages_endpoint_finds_a_number_by_its_last_digits(): void
    {
        $this->actingAsAdmin();

        MessageLog::factory()->create(['phone_number_id' => $this->phone->id, 'to_number' => '529231311146']);
        MessageLog::factory()->create(['phone_number_id' => $this->phone->id, 'to_number' => '526691273636']);

        $res = $this->getJson('/api/dashboard/messages?search=1146')->assertOk();

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('529231311146', $res->json('data.0.to_number'));
    }

    public function test_messages_endpoint_ignores_an_empty_search(): void
    {
        $this->actingAsAdmin();
        $this->createLogs(['sent', 'sent']);

        $res = $this->getJson('/api/dashboard/messages?search=' . urlencode('   '))->assertOk();

        $this->assertEquals(2, $res->json('meta.total'));
    }
}
