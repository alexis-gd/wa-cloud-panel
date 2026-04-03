<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureFlagsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed feature flags (la migración los inserta, RefreshDatabase los necesita aquí)
        $flags = [
            'feature_daily_chart'   => '1',
            'feature_conversations' => '1',
            'feature_export'        => '1',
            'feature_tags'          => '1',
            'feature_multi_agent'   => '1',
        ];

        foreach ($flags as $key => $value) {
            Setting::set($key, $value);
        }
    }

    public function test_get_features_returns_all_flags(): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson('/api/settings/features')
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $data = $res->json('data');
        $this->assertArrayHasKey('feature_daily_chart', $data);
        $this->assertArrayHasKey('feature_conversations', $data);
        $this->assertArrayHasKey('feature_export', $data);
        $this->assertArrayHasKey('feature_tags', $data);
        $this->assertArrayHasKey('feature_multi_agent', $data);
    }

    public function test_agent_can_read_feature_flags(): void
    {
        $this->actingAsAgent();

        $this->getJson('/api/settings/features')->assertOk();
    }

    public function test_operator_can_read_feature_flags(): void
    {
        $this->actingAsOperator();

        $this->getJson('/api/settings/features')->assertOk();
    }

    public function test_admin_can_update_feature_flags(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/settings/features', [
            'feature_conversations' => false,
            'feature_export'        => false,
        ])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.feature_conversations', false)
            ->assertJsonPath('data.feature_export', false);

        $this->assertEquals('0', Setting::get('feature_conversations'));
        $this->assertEquals('0', Setting::get('feature_export'));
    }

    public function test_operator_cannot_update_feature_flags(): void
    {
        $this->actingAsOperator();

        $this->putJson('/api/settings/features', ['feature_export' => false])
            ->assertStatus(403);
    }

    public function test_agent_cannot_update_feature_flags(): void
    {
        $this->actingAsAgent();

        $this->putJson('/api/settings/features', ['feature_export' => false])
            ->assertStatus(403);
    }

    public function test_flags_default_to_true(): void
    {
        $this->actingAsAdmin();

        $res = $this->getJson('/api/settings/features')->assertOk();
        $data = $res->json('data');

        foreach ($data as $key => $value) {
            $this->assertTrue($value, "Flag {$key} debería ser true por defecto");
        }
    }
}
