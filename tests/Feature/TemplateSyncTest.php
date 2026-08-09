<?php

namespace Tests\Feature;

use App\Models\PhoneNumber;
use App\Models\WaTemplate;
use App\Services\WhatsApp\TemplateSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El sync espeja el WABA configurado: agrega, actualiza y **retira** lo que ya no existe allá.
 * Antes solo agregaba, así que al cambiar de cuenta el panel seguía ofreciendo plantillas
 * fantasma y había que limpiarlas por SSH.
 */
class TemplateSyncTest extends TestCase
{
    use RefreshDatabase;

    private function fakeMeta(array $templates, array $paging = []): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['data' => $templates] + $paging, 200),
        ]);
    }

    private function metaTemplate(string $name, string $language = 'es_MX', string $status = 'APPROVED'): array
    {
        return [
            'name'       => $name,
            'language'   => $language,
            'status'     => $status,
            'components' => [
                ['type' => 'BODY', 'text' => 'Hola'],
            ],
        ];
    }

    public function test_retira_las_plantillas_que_ya_no_estan_en_meta(): void
    {
        PhoneNumber::factory()->create(['is_active' => true]);
        WaTemplate::factory()->create(['name' => 'vieja_de_otra_waba', 'language_code' => 'es_MX']);
        WaTemplate::factory()->create(['name' => 'sigue_viva', 'language_code' => 'es_MX']);

        $this->fakeMeta([$this->metaTemplate('sigue_viva')]);

        $result = app(TemplateSync::class)->run();

        $this->assertTrue($result['ok']);
        $this->assertEquals(1, $result['synced']);
        $this->assertEquals(1, $result['removed']);
        $this->assertDatabaseMissing('wa_templates', ['name' => 'vieja_de_otra_waba']);
        $this->assertDatabaseHas('wa_templates', ['name' => 'sigue_viva']);
    }

    /** La misma plantilla en otro idioma es otra plantilla: no debe retirarse por error. */
    public function test_distingue_plantillas_por_idioma(): void
    {
        PhoneNumber::factory()->create(['is_active' => true]);
        WaTemplate::factory()->create(['name' => 'saludo', 'language_code' => 'es_MX']);
        WaTemplate::factory()->create(['name' => 'saludo', 'language_code' => 'en_US']);

        $this->fakeMeta([
            $this->metaTemplate('saludo', 'es_MX'),
            $this->metaTemplate('saludo', 'en_US'),
        ]);

        $result = app(TemplateSync::class)->run();

        $this->assertEquals(0, $result['removed']);
        $this->assertEquals(2, WaTemplate::count());
    }

    public function test_no_borra_nada_si_meta_falla(): void
    {
        PhoneNumber::factory()->create(['is_active' => true]);
        WaTemplate::factory()->create(['name' => 'no_me_toques', 'language_code' => 'es_MX']);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth token']], 401),
        ]);

        $result = app(TemplateSync::class)->run();

        $this->assertFalse($result['ok']);
        $this->assertDatabaseHas('wa_templates', ['name' => 'no_me_toques']);
    }

    public function test_falla_sin_numero_activo_y_no_borra_nada(): void
    {
        WaTemplate::factory()->create(['name' => 'no_me_toques', 'language_code' => 'es_MX']);

        $result = app(TemplateSync::class)->run();

        $this->assertFalse($result['ok']);
        $this->assertDatabaseHas('wa_templates', ['name' => 'no_me_toques']);
    }

    /**
     * Con paginación, retirar en base a la primera página borraría las que no cupieron.
     * Meta manda `paging.next` mientras haya más.
     */
    public function test_pagina_antes_de_retirar(): void
    {
        PhoneNumber::factory()->create(['is_active' => true]);
        WaTemplate::factory()->create(['name' => 'pagina_dos', 'language_code' => 'es_MX']);

        $llamadas = 0;
        Http::fake(['graph.facebook.com/*' => function () use (&$llamadas) {
            $llamadas++;

            return $llamadas === 1
                ? Http::response([
                    'data'   => [$this->metaTemplate('pagina_uno')],
                    'paging' => ['cursors' => ['after' => 'CURSOR'], 'next' => 'https://graph.facebook.com/next'],
                ], 200)
                : Http::response(['data' => [$this->metaTemplate('pagina_dos')]], 200);
        }]);

        $result = app(TemplateSync::class)->run();

        $this->assertEquals(2, $llamadas);
        $this->assertEquals(2, $result['synced']);
        $this->assertEquals(0, $result['removed']);
        $this->assertDatabaseHas('wa_templates', ['name' => 'pagina_dos']);
    }

    public function test_el_endpoint_devuelve_cuantas_retiro(): void
    {
        PhoneNumber::factory()->create(['is_active' => true]);
        WaTemplate::factory()->create(['name' => 'fantasma', 'language_code' => 'es_MX']);

        $this->fakeMeta([$this->metaTemplate('viva')]);

        $this->actingAsAdmin()
             ->postJson('/api/templates/sync')
             ->assertStatus(200)
             ->assertJsonPath('synced', 1)
             ->assertJsonPath('removed', 1);
    }
}
