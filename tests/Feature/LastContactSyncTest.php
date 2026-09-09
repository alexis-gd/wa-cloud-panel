<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Registro de la última corrida de la sincronización.
 *
 * El cron corre a las 4 AM y nadie ve esa consola. Sin esto, al día siguiente no había
 * forma de saber qué pasó: el resumen completo solo existía en pantalla, y el `Log::info`
 * ni siquiera se escribe si el nivel de log de producción está en `warning`.
 */
class LastContactSyncTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://192.168.17.20:8001/clients';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'contact_sync.url'         => self::URL,
            'contact_sync.auth'        => 'basic',
            'contact_sync.user'        => 'sender',
            'contact_sync.password'    => 'secreto',
            'contact_sync.root'        => 'data',
            'contact_sync.field_phone' => 'Celular',
            'contact_sync.field_name'  => 'Nombre',
            'contact_sync.tag'         => null,
        ]);
    }

    private function responde(array $body, int $status = 200): void
    {
        Http::fake([self::URL => Http::response($body, $status)]);
    }

    private function respuesta(): array
    {
        return ['data' => [
            ['Celular' => '6692406890', 'Nombre' => 'A', 'Estado' => 'LIQUIDADO'],
            ['Celular' => '6691655905', 'Nombre' => 'B', 'Estado' => 'BURO'],
            ['Celular' => 'ilegible',   'Nombre' => 'C', 'Estado' => 'BAJA'],
        ]];
    }

    public function test_una_corrida_deja_su_resumen_guardado(): void
    {
        $this->responde($this->respuesta());

        $this->artisan('contactos:sincronizar')->assertSuccessful();

        $r = json_decode(Setting::get('contact_sync_last_run'), true);

        $this->assertTrue($r['ok']);
        $this->assertSame(3, $r['received']);
        $this->assertSame(1, $r['invalid']);
        $this->assertSame(2, $r['inserted']);
        $this->assertArrayHasKey('at', $r);
        $this->assertSame(['BURO' => 1, 'LIQUIDADO' => 1], $r['by_status']);
    }

    public function test_el_modo_seco_no_deja_registro(): void
    {
        // Una prueba en seco no es una corrida: si la guardara, el operador creería que
        // el cron corrió cuando en realidad alguien solo estaba mirando números.
        $this->responde($this->respuesta());

        $this->artisan('contactos:sincronizar', ['--dry-run' => true])->assertSuccessful();

        $this->assertNull(Setting::get('contact_sync_last_run'));
    }

    public function test_un_fallo_tambien_queda_registrado_con_su_motivo(): void
    {
        // Es el caso que más importa: si el cron falló de madrugada, al día siguiente hay
        // que poder saber POR QUÉ sin depender del log.
        $this->responde([], 500);

        $this->artisan('contactos:sincronizar')->assertFailed();

        $r = json_decode(Setting::get('contact_sync_last_run'), true);

        $this->assertFalse($r['ok']);
        $this->assertNotEmpty($r['error']);
    }

    public function test_el_comando_muestra_la_ultima_corrida(): void
    {
        $this->responde($this->respuesta());
        $this->artisan('contactos:sincronizar');

        $this->artisan('contactos:ultima-corrida')
             ->expectsOutputToContain('Registros recibidos del API')
             ->assertSuccessful();
    }

    public function test_el_comando_avisa_si_nunca_ha_corrido(): void
    {
        $this->artisan('contactos:ultima-corrida')
             ->expectsOutputToContain('Todavía no hay ninguna corrida registrada.')
             ->assertSuccessful();
    }

    public function test_el_comando_explica_el_fallo_en_vez_de_la_tabla(): void
    {
        Setting::set('contact_sync_last_run', json_encode([
            'at'    => now()->toIso8601String(),
            'ok'    => false,
            'error' => 'El login respondió HTTP 401.',
        ]));

        $this->artisan('contactos:ultima-corrida')
             ->expectsOutputToContain('LA ÚLTIMA CORRIDA FALLÓ')
             ->expectsOutputToContain('El login respondió HTTP 401.')
             ->assertSuccessful();
    }

    public function test_un_registro_corrupto_no_revienta(): void
    {
        Setting::set('contact_sync_last_run', 'esto no es json');

        $this->artisan('contactos:ultima-corrida')->assertFailed();
    }

    public function test_el_desglose_por_cartera(): void
    {
        Contact::create(['phone' => '529000000001', 'status' => 'active', 'source' => 'api', 'portfolio_status' => 'LIQUIDADO']);
        Contact::create(['phone' => '529000000002', 'status' => 'active', 'source' => 'api', 'portfolio_status' => 'LIQUIDADO']);
        Contact::create(['phone' => '529000000003', 'status' => 'active', 'source' => 'manual', 'portfolio_status' => null]);

        $this->artisan('contactos:ultima-corrida', ['--cartera' => true])
             ->expectsOutputToContain('LIQUIDADO')
             ->expectsOutputToContain('(sin estado)')
             ->assertSuccessful();
    }
}
