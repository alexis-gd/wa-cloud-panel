<?php

namespace Tests\Feature;

use App\Jobs\SendSmsMessage;
use App\Models\Campaign;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignSmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_crear_campana_sms_sin_plantilla_ni_numero(): void
    {
        $this->actingAsOperator();

        $this->postJson('/api/campaigns', [
            'name'     => 'Promo SMS',
            'channel'  => 'sms',
            'sms_body' => 'Prestamaz: préstamo desde $10,000. Responde STOP para baja.',
        ])
        ->assertStatus(201)
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('data.channel', 'sms')
        ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('campaigns', [
            'name'            => 'Promo SMS',
            'channel'         => 'sms',
            'phone_number_id' => null,
        ]);
    }

    public function test_crear_campana_sms_exige_sms_body(): void
    {
        $this->actingAsOperator();

        $this->postJson('/api/campaigns', [
            'name'    => 'SMS sin cuerpo',
            'channel' => 'sms',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('sms_body');
    }

    public function test_ejecutar_campana_sms_encola_jobs_sms(): void
    {
        Queue::fake();
        $this->actingAsOperator();

        Contact::factory()->count(3)->create(['status' => 'active']);

        $campaign = Campaign::factory()->create([
            'channel'         => 'sms',
            'sms_body'        => 'texto',
            'template_name'   => null,
            'phone_number_id' => null,
            'status'          => 'draft',
        ]);

        $this->postJson("/api/campaigns/{$campaign->id}/execute")
            ->assertStatus(200)
            ->assertJsonPath('data.channel', 'sms')
            ->assertJsonPath('data.jobs_dispatched', 3);

        Queue::assertPushed(SendSmsMessage::class, 3);
        $this->assertSame('running', $campaign->fresh()->status);
    }
}
