<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filtro por entregabilidad en Contactos, POR CANAL.
 *
 * Lo que se protege: que el filtro devuelva exactamente lo que la tabla rotula. Si el
 * operador filtra "Enfriamiento WhatsApp" y le salen filas que dicen "Enviado hoy", el
 * filtro miente. Por eso cada test compara contra la etiqueta que calcula el backend.
 */
class ContactDeliverabilityFilterTest extends TestCase
{
    use RefreshDatabase;

    private PhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
        $this->phone = PhoneNumber::factory()->create();
        Setting::set('cooldown_days', '30');
    }

    private function contact(string $phone, array $attrs = []): Contact
    {
        return Contact::create(array_merge([
            'phone'  => $phone,
            'name'   => 'Prueba',
            'status' => 'active',
            'source' => 'manual',
        ], $attrs));
    }

    private function log(string $to, $sentAt, string $channel = 'whatsapp', string $status = 'sent'): void
    {
        MessageLog::create([
            'phone_number_id' => $channel === 'sms' ? null : $this->phone->id,
            'to_number'       => $to,
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => $status,
            'sent_at'         => $sentAt,
            'channel'         => $channel,
        ]);
    }

    /** @return string[] teléfonos que devuelve el filtro */
    private function filter(string $option): array
    {
        return collect(
            $this->getJson('/api/contacts?deliverability=' . $option)->assertOk()->json('data')
        )->pluck('phone')->sort()->values()->all();
    }

    public function test_disponible_whatsapp_excluye_bloqueados_pospuestos_hold_y_enfriamiento(): void
    {
        $this->contact('529231111111');                                   // disponible
        $this->contact('529232222222', ['status' => 'opted_out']);        // no recibe
        $this->contact('529233333333', ['snoozed_until' => now()->addDay()]);
        $this->contact('529234444444', ['wa_marketing_hold_until' => now()->addHours(5)]);

        $enfriando = $this->contact('529235555555');
        $this->log($enfriando->phone, now()->subDays(3));

        $this->assertSame(['529231111111'], $this->filter('wa_available'));
    }

    public function test_enviado_hoy_y_enfriamiento_de_whatsapp_no_se_enciman(): void
    {
        $hoy = $this->contact('529231111111');
        $this->log($hoy->phone, now());

        $enfriando = $this->contact('529232222222');
        $this->log($enfriando->phone, now()->subDays(5));

        // "Enviado hoy" también cae dentro de la ventana de enfriamiento, pero la tabla lo
        // rotula "Enviado hoy": el filtro tiene que respetar esa misma precedencia.
        $this->assertSame(['529231111111'], $this->filter('wa_sent_today'));
        $this->assertSame(['529232222222'], $this->filter('wa_cooldown'));
    }

    public function test_fuera_de_la_ventana_de_enfriamiento_vuelve_a_disponible(): void
    {
        $viejo = $this->contact('529231111111');
        $this->log($viejo->phone, now()->subDays(40));

        $this->assertSame(['529231111111'], $this->filter('wa_available'));
        $this->assertSame([], $this->filter('wa_cooldown'));
    }

    public function test_pospuesto_y_hold_de_meta_tienen_su_propio_filtro(): void
    {
        $this->contact('529231111111', ['snoozed_until' => now()->addDay()]);
        $this->contact('529232222222', ['wa_marketing_hold_until' => now()->addHours(5)]);
        $this->contact('529233333333');

        $this->assertSame(['529231111111'], $this->filter('wa_snoozed'));
        $this->assertSame(['529232222222'], $this->filter('wa_hold'));
    }

    public function test_no_recibe_de_whatsapp_junta_baja_invalido_e_inalcanzable(): void
    {
        $this->contact('529231111111', ['status' => 'opted_out']);
        $this->contact('529232222222', ['status' => 'invalid']);
        $this->contact('529233333333', ['status' => 'unreachable']);
        $this->contact('529234444444');

        $this->assertSame(
            ['529231111111', '529232222222', '529233333333'],
            $this->filter('wa_blocked')
        );
    }

    public function test_el_enfriamiento_es_por_canal(): void
    {
        // Le entró un SMS hace 3 días: enfría SMS, NO enfría WhatsApp.
        $soloSms = $this->contact('529231111111');
        $this->log($soloSms->phone, now()->subDays(3), 'sms');

        $this->assertSame(['529231111111'], $this->filter('sms_cooldown'));
        $this->assertSame(['529231111111'], $this->filter('wa_available'));
        $this->assertSame([], $this->filter('wa_cooldown'));
    }

    public function test_el_enviado_hoy_es_por_canal(): void
    {
        $c = $this->contact('529231111111');
        $this->log($c->phone, now(), 'whatsapp');

        $this->assertSame(['529231111111'], $this->filter('wa_sent_today'));
        $this->assertSame([], $this->filter('sms_sent_today'));
        $this->assertSame(['529231111111'], $this->filter('sms_available'));
    }

    public function test_el_pospuesto_no_saca_al_contacto_del_sms(): void
    {
        // El snooze es solo de WhatsApp (ver contexto-sms): en SMS sigue disponible.
        $this->contact('529231111111', ['snoozed_until' => now()->addDay()]);

        $this->assertSame([], $this->filter('wa_available'));
        $this->assertSame(['529231111111'], $this->filter('sms_available'));
    }

    public function test_no_recibe_de_sms_junta_la_baja_general_y_las_banderas_de_sms(): void
    {
        $this->contact('529231111111', ['status' => 'opted_out']);   // baja cross-channel
        $this->contact('529232222222', ['sms_opt_out' => true]);
        $this->contact('529233333333', ['sms_blocked' => true]);
        $this->contact('529234444444', ['sms_invalid' => true]);
        $this->contact('529235555555');

        $this->assertSame(
            ['529231111111', '529232222222', '529233333333', '529234444444'],
            $this->filter('sms_blocked')
        );
        $this->assertSame(['529235555555'], $this->filter('sms_available'));
    }

    public function test_un_invalido_de_whatsapp_sigue_recibiendo_sms(): void
    {
        // "Inválido/Inalcanzable" es del eje WhatsApp; no dice nada del SMS.
        $this->contact('529231111111', ['status' => 'invalid']);

        $this->assertSame([], $this->filter('wa_available'));
        $this->assertSame(['529231111111'], $this->filter('sms_available'));
    }

    public function test_solo_cuentan_los_envios_que_llegaron(): void
    {
        // Un mensaje fallido no enfría a nadie: los jobs tampoco lo cuentan.
        $fallido = $this->contact('529231111111');
        $this->log($fallido->phone, now()->subDay(), 'whatsapp', 'failed');

        $this->assertSame(['529231111111'], $this->filter('wa_available'));
        $this->assertSame([], $this->filter('wa_cooldown'));
    }

    public function test_el_filtro_respeta_el_cooldown_configurado(): void
    {
        Setting::set('cooldown_days', '7');

        $c = $this->contact('529231111111');
        $this->log($c->phone, now()->subDays(10));

        // Con 30 días estaría enfriando; con 7 ya está disponible.
        $this->assertSame(['529231111111'], $this->filter('wa_available'));
    }

    public function test_el_filtro_se_combina_con_el_pegado_masivo(): void
    {
        $disponible = $this->contact('529231111111');
        $enfriando  = $this->contact('529232222222');
        $this->log($enfriando->phone, now()->subDays(3));
        $this->contact('529233333333'); // disponible pero fuera del pegado

        $res = $this->postJson('/api/contacts/search', [
            'phones_raw'     => '529231111111 529232222222',
            'deliverability' => 'wa_available',
        ])->assertOk();

        $this->assertSame(1, $res->json('total'));
        $this->assertSame('529231111111', $res->json('data.0.phone'));
    }

    public function test_varios_estados_se_suman(): void
    {
        $hoy = $this->contact('529231111111');
        $this->log($hoy->phone, now());

        $enfriando = $this->contact('529232222222');
        $this->log($enfriando->phone, now()->subDays(3));

        $this->contact('529233333333');                              // disponible
        $this->contact('529234444444', ['status' => 'opted_out']);   // no recibe

        // El operador marca los dos estados: la lista trae la union, no la interseccion.
        $this->assertSame(
            ['529231111111', '529232222222'],
            $this->filter('wa_sent_today,wa_cooldown')
        );
    }

    public function test_se_pueden_mezclar_estados_de_los_dos_canales(): void
    {
        $waHoy = $this->contact('529231111111');
        $this->log($waHoy->phone, now(), 'whatsapp');

        $smsEnfriando = $this->contact('529232222222');
        $this->log($smsEnfriando->phone, now()->subDays(3), 'sms');

        $this->contact('529233333333'); // ni uno ni otro

        $this->assertSame(
            ['529231111111', '529232222222'],
            $this->filter('wa_sent_today,sms_cooldown')
        );
    }

    public function test_el_acumulado_no_anula_los_demas_filtros(): void
    {
        // Los OR del filtro viven dentro de su propio parentesis: el filtro de estado
        // sigue mandando. Si se escapara, saldrian tambien los de baja.
        $activo = $this->contact('529231111111');
        $this->log($activo->phone, now());

        $baja = $this->contact('529232222222', ['status' => 'opted_out']);
        $this->log($baja->phone, now());

        $res = $this->getJson('/api/contacts?status=active&deliverability=wa_sent_today,wa_blocked')
            ->assertOk();

        $this->assertSame(1, $res->json('total'));
        $this->assertSame('529231111111', $res->json('data.0.phone'));
    }

    public function test_el_pegado_masivo_acepta_varios_estados_en_array(): void
    {
        $hoy = $this->contact('529231111111');
        $this->log($hoy->phone, now());

        $enfriando = $this->contact('529232222222');
        $this->log($enfriando->phone, now()->subDays(3));

        $this->contact('529233333333'); // disponible: fuera del filtro

        $res = $this->postJson('/api/contacts/search', [
            'phones_raw'     => '529231111111 529232222222 529233333333',
            'deliverability' => ['wa_sent_today', 'wa_cooldown'],
        ])->assertOk();

        $this->assertSame(2, $res->json('total'));
    }

    public function test_los_valores_desconocidos_de_la_lista_se_ignoran(): void
    {
        $hoy = $this->contact('529231111111');
        $this->log($hoy->phone, now());
        $this->contact('529232222222');

        $this->assertSame(['529231111111'], $this->filter('wa_sent_today,cualquier_cosa'));
    }

    public function test_un_valor_desconocido_no_filtra_nada(): void
    {
        $this->contact('529231111111');
        $this->contact('529232222222');

        $this->assertCount(2, $this->filter('cualquier_cosa'));
    }

    public function test_la_etiqueta_de_la_tabla_coincide_con_el_filtro(): void
    {
        $hoy = $this->contact('529231111111');
        $this->log($hoy->phone, now());

        $row = $this->getJson('/api/contacts?deliverability=wa_sent_today')->assertOk()->json('data.0');

        $this->assertTrue($row['sent_today']);
        $this->assertFalse($row['deliverable']);
    }
}
