<?php

namespace Tests\Feature;

use App\Events\CampaignProgressUpdated;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\PhoneNumber;
use App\Services\WhatsApp\TemplateBuilder;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Tiempo real de campanas: el worker emite CampaignProgressUpdated mientras envia,
 * el panel lo escucha por WebSocket para subir contadores/estado sin polling.
 */
class CampaignProgressBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private function makeJob(Campaign $campaign, Contact $contact, PhoneNumber $phone): SendWhatsAppMessage
    {
        return new SendWhatsAppMessage(
            contactId:     $contact->id,
            campaignId:    $campaign->id,
            phoneNumberId: $phone->id,
            templateName:  'hello_world',
            languageCode:  'en_US',
            bodyVars:      [],
        );
    }

    private function mockSuccessfulClient(): void
    {
        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldReceive('post')->andReturn([
                'ok'   => true,
                'body' => ['messages' => [['id' => 'wamid.test123']]],
            ]);
        });
    }

    public function test_envio_exitoso_emite_evento_de_progreso(): void
    {
        Event::fake([CampaignProgressUpdated::class]);
        $this->mockSuccessfulClient();

        $phone    = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 250]);
        $contact  = Contact::factory()->create(['phone' => '521234567890', 'status' => 'active']);
        $campaign = Campaign::factory()->create([
            'phone_number_id' => $phone->id,
            'status'          => 'running',
            'total_contacts'  => 5,
        ]);

        $this->makeJob($campaign, $contact, $phone)->handle(
            app(WhatsAppClient::class),
            app(TemplateBuilder::class),
        );

        Event::assertDispatched(
            CampaignProgressUpdated::class,
            fn ($e) => $e->campaignId === $campaign->id
                && $e->sentCount === 1
                && $e->status === 'running',
        );
    }

    public function test_ultimo_envio_emite_evento_final_con_estado_completada(): void
    {
        Event::fake([CampaignProgressUpdated::class]);
        $this->mockSuccessfulClient();

        $phone    = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 250]);
        $contact  = Contact::factory()->create(['phone' => '521234567890', 'status' => 'active']);
        // total_contacts = 1: este unico envio completa la campana (evento final forzado).
        $campaign = Campaign::factory()->create([
            'phone_number_id' => $phone->id,
            'status'          => 'running',
            'total_contacts'  => 1,
        ]);

        $this->makeJob($campaign, $contact, $phone)->handle(
            app(WhatsAppClient::class),
            app(TemplateBuilder::class),
        );

        Event::assertDispatched(
            CampaignProgressUpdated::class,
            fn ($e) => $e->campaignId === $campaign->id
                && $e->sentCount === 1
                && $e->status === 'completed',
        );
    }

    public function test_evento_va_al_canal_privado_campaigns_con_payload(): void
    {
        $event = new CampaignProgressUpdated(9, 12, 3, 200, 'running');

        $this->assertSame('private-campaigns', $event->broadcastOn()->name);
        $this->assertSame('campaign.progress', $event->broadcastAs());

        $payload = $event->broadcastWith();
        $this->assertSame(9, $payload['campaign_id']);
        $this->assertSame(12, $payload['sent_count']);
        $this->assertSame(3, $payload['failed_count']);
        $this->assertSame(200, $payload['total_contacts']);
        $this->assertSame('running', $payload['status']);
    }
}
