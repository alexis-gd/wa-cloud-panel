<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactDeliverabilityTest extends TestCase
{
    use RefreshDatabase;

    private PhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->phone = PhoneNumber::factory()->create();
    }

    private function log(string $to, string $status, $sentAt): void
    {
        MessageLog::create([
            'phone_number_id' => $this->phone->id,
            'to_number'       => $to,
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => $status,
            'sent_at'         => $sentAt,
        ]);
    }

    private function fetch(int $contactId): array
    {
        $res = $this->getJson('/api/contacts')->assertOk()->json('data');
        return collect($res)->firstWhere('id', $contactId);
    }

    public function test_index_incluye_campos_de_entregabilidad(): void
    {
        $this->actingAsOperator();
        Contact::factory()->create(['status' => 'active']);

        $this->getJson('/api/contacts')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'sent_today', 'cooldown_active', 'cooldown_until', 'deliverable']]]);
    }

    public function test_activo_sin_historial_es_deliverable(): void
    {
        $this->actingAsOperator();
        $c = Contact::factory()->create(['status' => 'active']);

        $row = $this->fetch($c->id);
        $this->assertTrue($row['deliverable']);
        $this->assertFalse($row['sent_today']);
        $this->assertFalse($row['cooldown_active']);
    }

    public function test_enviado_hoy_no_es_deliverable(): void
    {
        $this->actingAsOperator();
        $c = Contact::factory()->create(['status' => 'active', 'phone' => '521230000001']);
        $this->log($c->phone, 'delivered', now());

        $row = $this->fetch($c->id);
        $this->assertTrue($row['sent_today']);
        $this->assertFalse($row['deliverable']);
    }

    public function test_cooldown_activo_no_es_deliverable(): void
    {
        $this->actingAsOperator();
        Setting::set('cooldown_days', '30');
        $c = Contact::factory()->create(['status' => 'active', 'phone' => '521230000002']);
        $this->log($c->phone, 'sent', now()->subDays(5));

        $row = $this->fetch($c->id);
        $this->assertTrue($row['cooldown_active']);
        $this->assertNotNull($row['cooldown_until']);
        $this->assertFalse($row['deliverable']);
    }

    public function test_bloqueado_no_es_deliverable(): void
    {
        $this->actingAsOperator();
        $c = Contact::factory()->create(['status' => 'opted_out', 'phone' => '521230000003']);

        $row = $this->fetch($c->id);
        $this->assertFalse($row['deliverable']);
    }

    public function test_snooze_activo_no_es_deliverable(): void
    {
        $this->actingAsOperator();
        $c = Contact::factory()->create([
            'status'        => 'active',
            'phone'         => '521230000004',
            'snoozed_until' => now()->addDays(3),
        ]);

        $row = $this->fetch($c->id);
        $this->assertTrue($row['snooze_active']);
        $this->assertNotNull($row['snooze_until']);
        $this->assertFalse($row['deliverable']);
    }

    public function test_snooze_vencido_no_afecta(): void
    {
        $this->actingAsOperator();
        $c = Contact::factory()->create([
            'status'        => 'active',
            'phone'         => '521230000005',
            'snoozed_until' => now()->subDay(), // ya venció
        ]);

        $row = $this->fetch($c->id);
        $this->assertFalse($row['snooze_active']);
        $this->assertTrue($row['deliverable']);
    }
}
