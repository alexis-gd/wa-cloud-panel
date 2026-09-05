<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API de contactados por fecha (S1). La consume un sistema externo del cliente, no el panel:
 * autentica con `X-API-Key`, no con Sanctum.
 *
 * "Contactado" = el mensaje salió (sent / delivered / read), los dos canales, UNA fila por
 * contacto. Mismo criterio que usa el sistema para enfriamiento y dedup, para que el número
 * que devuelve el API cuadre con lo que el operador ve.
 */
class ContactedApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'llave-de-prueba';

    private PhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.api_key' => self::KEY]);
        $this->phone = PhoneNumber::factory()->create();
    }

    private function consultar(string $url)
    {
        return $this->getJson($url, ['X-API-Key' => self::KEY]);
    }

    private function contacto(string $phone, ?string $name = 'Prueba'): Contact
    {
        return Contact::create([
            'phone'  => $phone,
            'name'   => $name,
            'status' => 'active',
            'source' => 'manual',
        ]);
    }

    private function log(string $to, string $sentAt, string $status = 'sent', string $channel = 'whatsapp'): void
    {
        MessageLog::create([
            'phone_number_id' => $channel === 'sms' ? null : $this->phone->id,
            'to_number'       => $to,
            'template_name'   => 'hello_world',
            'language_code'   => 'en_US',
            'body_vars'       => [],
            'status'          => $status,
            // La fecha se recibe en hora de México y se guarda en UTC, igual que en producción.
            'sent_at'         => \Illuminate\Support\Carbon::parse($sentAt, 'America/Mexico_City')->utc(),
            'channel'         => $channel,
        ]);
    }

    // ── Seguridad ────────────────────────────────────────────────────────────

    public function test_sin_llave_no_responde(): void
    {
        $this->getJson('/api/contacted?date=2026-08-17')->assertStatus(401);
    }

    public function test_con_llave_incorrecta_no_responde(): void
    {
        $this->getJson('/api/contacted?date=2026-08-17', ['X-API-Key' => 'otra'])->assertStatus(401);
    }

    // ── Fecha única ──────────────────────────────────────────────────────────

    public function test_devuelve_nombre_y_numero_de_los_contactados_ese_dia(): void
    {
        $this->contacto('529231111111', 'Juan Pérez');
        $this->log('529231111111', '2026-08-17 10:00');

        $res = $this->consultar('/api/contacted?date=2026-08-17')->assertOk();

        $this->assertSame('ok', $res->json('status'));
        $this->assertSame([['name' => 'Juan Pérez', 'phone' => '529231111111']], $res->json('data'));
        $this->assertSame(1, $res->json('meta.total'));
    }

    public function test_no_devuelve_a_quien_se_contacto_otro_dia(): void
    {
        $this->contacto('529231111111');
        $this->log('529231111111', '2026-08-16 23:00');

        $this->consultar('/api/contacted?date=2026-08-17')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_el_dia_va_de_00_00_a_23_59_hora_de_mexico(): void
    {
        // Un mensaje de las 23:30 CST del 17 es de las 05:30 UTC del 18. Con el corte en UTC
        // se saldría del día que pidió el cliente.
        $this->contacto('529231111111');
        $this->log('529231111111', '2026-08-17 23:30');

        $this->consultar('/api/contacted?date=2026-08-17')->assertOk()->assertJsonPath('meta.total', 1);
        $this->consultar('/api/contacted?date=2026-08-18')->assertOk()->assertJsonPath('meta.total', 0);
    }

    // ── Criterio de "contactado" ─────────────────────────────────────────────

    public function test_un_mensaje_fallido_no_cuenta_como_contactado(): void
    {
        $this->contacto('529231111111');
        $this->log('529231111111', '2026-08-17 10:00', 'failed');

        $this->consultar('/api/contacted?date=2026-08-17')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_un_descartado_no_cuenta_como_contactado(): void
    {
        $this->contacto('529231111111');
        $this->log('529231111111', '2026-08-17 10:00', 'discarded');

        $this->consultar('/api/contacted?date=2026-08-17')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_cuenta_entregados_y_leidos(): void
    {
        $this->contacto('529231111111');
        $this->contacto('529232222222');
        $this->log('529231111111', '2026-08-17 10:00', 'delivered');
        $this->log('529232222222', '2026-08-17 11:00', 'read');

        $this->consultar('/api/contacted?date=2026-08-17')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_cuenta_los_dos_canales(): void
    {
        $this->contacto('529231111111');
        $this->contacto('529232222222');
        $this->log('529231111111', '2026-08-17 10:00', 'sent', 'whatsapp');
        $this->log('529232222222', '2026-08-17 10:00', 'sent', 'sms');

        $this->consultar('/api/contacted?date=2026-08-17')->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_varios_mensajes_al_mismo_contacto_son_una_sola_fila(): void
    {
        // Tres envíos el mismo día es UNA persona contactada, no tres.
        $this->contacto('529231111111');
        $this->log('529231111111', '2026-08-17 09:00');
        $this->log('529231111111', '2026-08-17 13:00', 'sent', 'sms');
        $this->log('529231111111', '2026-08-17 18:00', 'delivered');

        $res = $this->consultar('/api/contacted?date=2026-08-17')->assertOk();

        $this->assertSame(1, $res->json('meta.total'));
        $this->assertCount(1, $res->json('data'));
    }

    public function test_un_contactado_sin_ficha_de_contacto_sale_sin_nombre(): void
    {
        // Se le contactó: esconderlo daría un total que no cuadra con los envíos.
        $this->log('529239999999', '2026-08-17 10:00');

        $res = $this->consultar('/api/contacted?date=2026-08-17')->assertOk();

        $this->assertSame([['name' => null, 'phone' => '529239999999']], $res->json('data'));
    }

    // ── Rango ────────────────────────────────────────────────────────────────

    public function test_el_rango_incluye_las_dos_fechas(): void
    {
        $this->contacto('529231111111');
        $this->contacto('529232222222');
        $this->contacto('529233333333');
        $this->log('529231111111', '2026-08-01 10:00');
        $this->log('529232222222', '2026-08-10 10:00');
        $this->log('529233333333', '2026-08-17 10:00');

        $res = $this->consultar('/api/contacted?from=2026-08-01&to=2026-08-17')->assertOk();

        $this->assertSame(3, $res->json('meta.total'));
    }

    public function test_un_rango_al_reves_se_rechaza(): void
    {
        $this->consultar('/api/contacted?from=2026-08-17&to=2026-08-01')
             ->assertStatus(422)
             ->assertJsonPath('code', 'INVALID_RANGE');
    }

    public function test_mandar_fecha_y_rango_a_la_vez_se_rechaza(): void
    {
        $this->consultar('/api/contacted?date=2026-08-17&from=2026-08-01&to=2026-08-17')
             ->assertStatus(422)
             ->assertJsonPath('code', 'AMBIGUOUS_RANGE');
    }

    public function test_sin_fecha_se_rechaza(): void
    {
        // Sin esto, una llamada sin parámetros barrería la tabla entera.
        $this->consultar('/api/contacted')->assertStatus(422)->assertJsonPath('code', 'MISSING_DATE');
    }

    public function test_una_fecha_mal_escrita_se_rechaza(): void
    {
        $this->consultar('/api/contacted?date=17-08-2026')->assertStatus(422);
    }

    public function test_from_sin_to_se_rechaza(): void
    {
        $this->consultar('/api/contacted?from=2026-08-01')->assertStatus(422);
    }

    // ── Paginación ───────────────────────────────────────────────────────────

    public function test_pagina_los_resultados(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $phone = '52923000000' . $i;
            $this->contacto($phone, "Contacto {$i}");
            $this->log($phone, '2026-08-17 10:00');
        }

        $res = $this->consultar('/api/contacted?date=2026-08-17&per_page=2&page=2')->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertSame(5, $res->json('meta.total'));
        $this->assertSame(3, $res->json('meta.pages'));
        $this->assertSame(2, $res->json('meta.page'));
    }

    public function test_las_paginas_no_repiten_ni_pierden_contactos(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $phone = '52923000000' . $i;
            $this->contacto($phone);
            $this->log($phone, '2026-08-17 10:00');
        }

        $todos = collect();
        foreach ([1, 2, 3] as $page) {
            $todos = $todos->merge(
                collect($this->consultar("/api/contacted?date=2026-08-17&per_page=2&page={$page}")->json('data'))
                    ->pluck('phone')
            );
        }

        $this->assertCount(5, $todos->unique());
    }

    public function test_un_per_page_gigante_se_topa(): void
    {
        $this->contacto('529231111111');
        $this->log('529231111111', '2026-08-17 10:00');

        $this->consultar('/api/contacted?date=2026-08-17&per_page=999999')
             ->assertOk()
             ->assertJsonPath('meta.per_page', \App\Services\Contacts\ContactedLookup::MAX_PER_PAGE);
    }

    public function test_la_meta_repite_las_fechas_consultadas(): void
    {
        $res = $this->consultar('/api/contacted?from=2026-08-01&to=2026-08-17')->assertOk();

        $this->assertSame('2026-08-01', $res->json('meta.from'));
        $this->assertSame('2026-08-17', $res->json('meta.to'));
    }
}
