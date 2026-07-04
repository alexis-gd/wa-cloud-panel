<?php

namespace Tests\Feature;

use App\Jobs\SendSmsMessage;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\SmsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignSmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_crear_campana_sms_usa_plantilla_y_snapshotea_cuerpo(): void
    {
        $this->actingAsOperator();
        $tpl = SmsTemplate::factory()->create(['body' => 'Prestamaz: promo. STOP para baja.']);

        $this->postJson('/api/campaigns', [
            'name'            => 'Promo SMS',
            'channel'         => 'sms',
            'sms_template_id' => $tpl->id,
        ])
        ->assertStatus(201)
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('data.channel', 'sms')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonPath('data.sms_template.name', $tpl->name);

        // El cuerpo se toma de la plantilla (snapshot), no de texto libre.
        $this->assertDatabaseHas('campaigns', [
            'name'            => 'Promo SMS',
            'channel'         => 'sms',
            'sms_template_id' => $tpl->id,
            'sms_body'        => 'Prestamaz: promo. STOP para baja.',
            'phone_number_id' => null,
        ]);
    }

    public function test_crear_campana_sms_exige_plantilla(): void
    {
        $this->actingAsOperator();

        $this->postJson('/api/campaigns', [
            'name'    => 'SMS sin plantilla',
            'channel' => 'sms',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('sms_template_id');
    }

    public function test_crear_campana_sms_rechaza_plantilla_inactiva(): void
    {
        $this->actingAsOperator();
        $tpl = SmsTemplate::factory()->create(['is_active' => false]);

        $this->postJson('/api/campaigns', [
            'name'            => 'SMS inactiva',
            'channel'         => 'sms',
            'sms_template_id' => $tpl->id,
        ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'INVALID_SMS_TEMPLATE');
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
