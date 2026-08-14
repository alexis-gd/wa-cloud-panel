<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Por qué no llegó un mensaje: el operador tiene que poder verlo sin adivinar.
 *
 * Regresión: el detalle de campaña leía solo `error_message`, que se llena cuando Meta rechaza
 * AL DESPACHAR. Las fallas de ENTREGA (webhook: 131049 y compañía) guardan
 * `delivery_error_code` y salían como un guion en la columna Motivo.
 */
class DeliveryReasonTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;
    private PhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->phone    = PhoneNumber::factory()->create(['is_active' => true]);
        $this->campaign = Campaign::factory()->create(['phone_number_id' => $this->phone->id]);
    }

    private function log(array $attrs): MessageLog
    {
        return MessageLog::create(array_merge([
            'phone_number_id' => $this->phone->id,
            'campaign_id'     => $this->campaign->id,
            'to_number'       => '526693322152',
            'template_name'   => 'promo',
            'language_code'   => 'es_MX',
            'body_vars'       => [],
            'status'          => 'failed',
            'sent_at'         => now(),
        ], $attrs));
    }

    private function logs(array $params = []): array
    {
        return $this->actingAsOperator()
            ->getJson("/api/campaigns/{$this->campaign->id}/logs?" . http_build_query($params))
            ->assertStatus(200)
            ->json('data');
    }

    public function test_traduce_el_tope_de_marketing_por_usuario(): void
    {
        $this->log(['delivery_error_code' => 131049, 'delivery_error_title' => 'Marketing limit']);

        $fila = $this->logs()[0];

        $this->assertStringContainsString('límite de mensajes de marketing', $fila['reason']);
        $this->assertStringContainsString('131049', $fila['reason_detail']);
    }

    /** Un código que no tenemos mapeado no debe dejar la columna vacía. */
    public function test_codigo_desconocido_cae_al_titulo_de_meta(): void
    {
        $this->log(['delivery_error_code' => 999999, 'delivery_error_title' => 'Something odd']);

        $this->assertEquals('Something odd', $this->logs()[0]['reason']);
    }

    public function test_traduce_el_motivo_de_un_descarte(): void
    {
        $this->log(['status' => 'discarded', 'discard_reason' => 'marketing_hold']);

        $fila = $this->logs()[0];

        $this->assertEquals('En espera (Meta)', $fila['reason']);
        $this->assertStringContainsString('24 horas', $fila['reason_detail']);
    }

    public function test_traduce_el_error_de_meta_al_despachar(): void
    {
        $this->log(['error_message' => json_encode(['code' => 131049, 'message' => 'Marketing limit reached'])]);

        $this->assertStringContainsString('límite de mensajes de marketing', $this->logs()[0]['reason']);
    }

    /** El gateway SMS manda texto plano, no JSON. */
    public function test_conserva_el_texto_plano_del_gateway_sms(): void
    {
        $this->log(['channel' => 'sms', 'error_message' => 'RESULT_ERROR_GENERIC_FAILURE']);

        $this->assertEquals('RESULT_ERROR_GENERIC_FAILURE', $this->logs()[0]['reason']);
    }

    public function test_un_mensaje_entregado_no_tiene_motivo(): void
    {
        $this->log(['status' => 'delivered']);

        $this->assertNull($this->logs()[0]['reason']);
    }

    // ── Filtro por estado ─────────────────────────────────────────────────────

    public function test_filtra_solo_los_fallidos(): void
    {
        $this->log(['status' => 'delivered', 'to_number' => '521111111111']);
        $this->log(['status' => 'failed',    'to_number' => '522222222222', 'delivery_error_code' => 131049]);
        $this->log(['status' => 'discarded', 'to_number' => '523333333333', 'discard_reason' => 'cooldown']);

        $fallidos = $this->logs(['status' => 'failed']);

        $this->assertCount(1, $fallidos);
        $this->assertEquals('522222222222', $fallidos[0]['to_number']);
    }

    public function test_sin_filtro_devuelve_todos(): void
    {
        $this->log(['status' => 'delivered', 'to_number' => '521111111111']);
        $this->log(['status' => 'failed',    'to_number' => '522222222222']);

        $this->assertCount(2, $this->logs());
    }

    public function test_un_estado_invalido_no_filtra_nada(): void
    {
        $this->log(['status' => 'delivered', 'to_number' => '521111111111']);

        $this->assertCount(1, $this->logs(['status' => 'inventado']));
    }

    // ── Hold de 24h visible en Contactos ──────────────────────────────────────

    public function test_contacto_en_hold_no_aparece_como_disponible(): void
    {
        Contact::factory()->create([
            'phone'                   => '526693322152',
            'status'                  => 'active',
            'wa_marketing_hold_until' => now()->addHours(20),
        ]);

        $contacto = $this->actingAsOperator()
            ->getJson('/api/contacts?q=526693322152')
            ->assertStatus(200)
            ->json('data.0');

        $this->assertTrue($contacto['wa_marketing_hold']);
        $this->assertFalse($contacto['deliverable']);
        $this->assertNotNull($contacto['wa_marketing_hold_until_label']);
    }

    public function test_contacto_con_hold_vencido_vuelve_a_estar_disponible(): void
    {
        Contact::factory()->create([
            'phone'                   => '526693322152',
            'status'                  => 'active',
            'wa_marketing_hold_until' => now()->subHour(),
        ]);

        $contacto = $this->actingAsOperator()
            ->getJson('/api/contacts?q=526693322152')
            ->json('data.0');

        $this->assertFalse($contacto['wa_marketing_hold']);
        $this->assertTrue($contacto['deliverable']);
    }
}
