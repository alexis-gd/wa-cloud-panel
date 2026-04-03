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

    public function test_stats_endpoint_no_longer_returns_recent_messages(): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson('/api/dashboard/stats')->assertOk();

        // El endpoint de stats ya no debe incluir recent_messages
        $this->assertArrayNotHasKey('recent_messages', $res->json('data'));
    }
}
