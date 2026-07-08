<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsAppMessage;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Services\WhatsApp\TemplateBuilder;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarkUnreachableContactsTest extends TestCase
{
    use RefreshDatabase;

    private PhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->phone = PhoneNumber::factory()->create(['is_active' => true, 'daily_limit' => 250]);
    }

    private function log(string $phone, string $status, $sentAt): void
    {
        MessageLog::create([
            'phone_number_id' => $this->phone->id,
            'to_number'       => $phone,
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => $status,
            'sent_at'         => $sentAt,
        ]);
    }

    public function test_marca_contacto_con_dos_sent_viejos_sin_entrega(): void
    {
        $contact = Contact::factory()->create(['phone' => '521111111111', 'status' => 'active']);
        $this->log($contact->phone, 'sent', now()->subDays(40));
        $this->log($contact->phone, 'sent', now()->subDays(35));

        $this->artisan('wa:mark-unreachable')->assertExitCode(0);

        $this->assertEquals('unreachable', $contact->fresh()->status);
    }

    public function test_no_marca_si_el_sent_mas_antiguo_tiene_menos_de_30_dias(): void
    {
        $contact = Contact::factory()->create(['phone' => '521111111112', 'status' => 'active']);
        $this->log($contact->phone, 'sent', now()->subDays(40));
        $this->log($contact->phone, 'sent', now()->subDays(10)); // reciente, pero el MIN es 40...

        // El MIN(sent_at) es 40 días → cumpliría. Para validar el borde, otro contacto
        // con AMBOS recientes NO debe marcarse.
        $reciente = Contact::factory()->create(['phone' => '521111111113', 'status' => 'active']);
        $this->log($reciente->phone, 'sent', now()->subDays(5));
        $this->log($reciente->phone, 'sent', now()->subDays(3));

        $this->artisan('wa:mark-unreachable')->assertExitCode(0);

        $this->assertEquals('unreachable', $contact->fresh()->status); // MIN 40d → sí
        $this->assertEquals('active', $reciente->fresh()->status);      // MIN 5d → no
    }

    public function test_no_marca_si_tiene_algun_delivered_en_su_historia(): void
    {
        $contact = Contact::factory()->create(['phone' => '521111111114', 'status' => 'active']);
        $this->log($contact->phone, 'sent', now()->subDays(40));
        $this->log($contact->phone, 'sent', now()->subDays(35));
        $this->log($contact->phone, 'delivered', now()->subDays(50)); // alguna vez sí entregó

        $this->artisan('wa:mark-unreachable')->assertExitCode(0);

        $this->assertEquals('active', $contact->fresh()->status);
    }

    public function test_no_marca_si_solo_tiene_un_sent(): void
    {
        $contact = Contact::factory()->create(['phone' => '521111111115', 'status' => 'active']);
        $this->log($contact->phone, 'sent', now()->subDays(40));

        $this->artisan('wa:mark-unreachable')->assertExitCode(0);

        $this->assertEquals('active', $contact->fresh()->status);
    }

    public function test_marca_con_tres_sent_seguidos_aunque_sean_recientes(): void
    {
        // Regla B: 3 no-entregados seguidos SIN esperar 30 días (tope fijo).
        $contact = Contact::factory()->create(['phone' => '521111111120', 'status' => 'active']);
        $this->log($contact->phone, 'sent', now()->subDays(14));
        $this->log($contact->phone, 'sent', now()->subDays(7));
        $this->log($contact->phone, 'sent', now()->subDays(1));

        $this->artisan('wa:mark-unreachable')->assertExitCode(0);

        $this->assertEquals('unreachable', $contact->fresh()->status);
    }

    public function test_no_marca_con_solo_dos_sent_recientes(): void
    {
        // 2 no-entregados recientes: por debajo del tope de 3 y el más viejo < 30 días.
        $contact = Contact::factory()->create(['phone' => '521111111121', 'status' => 'active']);
        $this->log($contact->phone, 'sent', now()->subDays(10));
        $this->log($contact->phone, 'sent', now()->subDays(3));

        $this->artisan('wa:mark-unreachable')->assertExitCode(0);

        $this->assertEquals('active', $contact->fresh()->status);
    }

    public function test_una_entrega_en_medio_reinicia_la_cuenta(): void
    {
        // sent, sent, delivered (reinicia), sent → solo 1 no-entregado tras la última entrega.
        $contact = Contact::factory()->create(['phone' => '521111111122', 'status' => 'active']);
        $this->log($contact->phone, 'sent',      now()->subDays(20));
        $this->log($contact->phone, 'sent',      now()->subDays(15));
        $this->log($contact->phone, 'delivered', now()->subDays(10));
        $this->log($contact->phone, 'sent',      now()->subDays(3));

        $this->artisan('wa:mark-unreachable')->assertExitCode(0);

        $this->assertEquals('active', $contact->fresh()->status);
    }

    public function test_marca_si_hay_tres_sent_despues_de_una_entrega_vieja(): void
    {
        // Entregó 1 vez y luego bloqueó: 3 no-entregados DESPUÉS de la última entrega → se marca.
        $contact = Contact::factory()->create(['phone' => '521111111123', 'status' => 'active']);
        $this->log($contact->phone, 'delivered', now()->subDays(40));
        $this->log($contact->phone, 'sent',      now()->subDays(20));
        $this->log($contact->phone, 'sent',      now()->subDays(12));
        $this->log($contact->phone, 'sent',      now()->subDays(4));

        $this->artisan('wa:mark-unreachable')->assertExitCode(0);

        $this->assertEquals('unreachable', $contact->fresh()->status);
    }

    public function test_no_marca_contactos_opted_out_o_invalid(): void
    {
        $optedOut = Contact::factory()->create(['phone' => '521111111116', 'status' => 'opted_out']);
        $invalid  = Contact::factory()->create(['phone' => '521111111117', 'status' => 'invalid']);

        foreach ([$optedOut, $invalid] as $c) {
            $this->log($c->phone, 'sent', now()->subDays(40));
            $this->log($c->phone, 'sent', now()->subDays(35));
        }

        $this->artisan('wa:mark-unreachable')->assertExitCode(0);

        $this->assertEquals('opted_out', $optedOut->fresh()->status);
        $this->assertEquals('invalid', $invalid->fresh()->status);
    }

    public function test_job_descarta_contacto_unreachable(): void
    {
        $campaign = Campaign::factory()->create([
            'phone_number_id' => $this->phone->id,
            'status'          => 'running',
            'total_contacts'  => 1,
            'sent_count'      => 0,
            'failed_count'    => 0,
        ]);
        $contact = Contact::factory()->create(['phone' => '521111111118', 'status' => 'unreachable']);

        $this->mock(WhatsAppClient::class, fn ($mock) => $mock->shouldReceive('post')->never());

        $job = new SendWhatsAppMessage(
            contactId:     $contact->id,
            campaignId:    $campaign->id,
            phoneNumberId: $this->phone->id,
            templateName:  'hello_world',
            languageCode:  'en_US',
            bodyVars:      [],
        );

        $job->handle(app(WhatsAppClient::class), app(TemplateBuilder::class));

        $this->assertDatabaseHas('message_log', [
            'campaign_id'    => $campaign->id,
            'to_number'      => $contact->phone,
            'status'         => 'discarded',
            'discard_reason' => 'unreachable',
        ]);
        $this->assertEquals(1, $campaign->fresh()->failed_count);
    }
}
