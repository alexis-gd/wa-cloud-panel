<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Models\Setting;
use App\Services\WhatsApp\MessagePersonalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `{nombre}` se resuelve por contacto al enviar. Meta rechaza el mensaje si una variable va
 * vacía o con saltos de línea, así que el resultado nunca puede quedar en blanco.
 */
class MessagePersonalizerTest extends TestCase
{
    use RefreshDatabase;

    private MessagePersonalizer $personalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->personalizer = new MessagePersonalizer();
    }

    private function contact(?string $name): Contact
    {
        return Contact::factory()->make(['name' => $name]);
    }

    public function test_sustituye_el_nombre_del_contacto(): void
    {
        $result = $this->personalizer->resolve(['{nombre}'], $this->contact('Joseph'));

        $this->assertEquals(['Joseph'], $result);
    }

    /** Las bases vienen de Excel: mayúsculas, nombre completo, espacios de más. */
    public function test_usa_solo_el_primer_nombre_y_lo_capitaliza(): void
    {
        $result = $this->personalizer->resolve(['{nombre}'], $this->contact('JUAN  PEREZ GARCIA'));

        $this->assertEquals(['Juan'], $result);
    }

    public function test_respeta_acentos(): void
    {
        $result = $this->personalizer->resolve(['{nombre}'], $this->contact('josé luis'));

        $this->assertEquals(['José'], $result);
    }

    public function test_usa_el_respaldo_si_el_contacto_no_tiene_nombre(): void
    {
        foreach ([null, '', '   '] as $sinNombre) {
            $result = $this->personalizer->resolve(['{nombre}'], $this->contact($sinNombre));

            $this->assertEquals(['cliente'], $result, 'Nombre vacío debe caer al respaldo');
        }
    }

    /** Un "nombre" que es un número o un símbolo no sirve para saludar. */
    public function test_usa_el_respaldo_si_el_nombre_es_basura(): void
    {
        $this->assertEquals(['cliente'], $this->personalizer->resolve(['{nombre}'], $this->contact('123456')));
        $this->assertEquals(['cliente'], $this->personalizer->resolve(['{nombre}'], $this->contact('-')));
    }

    public function test_el_respaldo_es_configurable(): void
    {
        Setting::set('personalization_fallback', 'amigo');

        $result = $this->personalizer->resolve(['{nombre}'], $this->contact(null));

        $this->assertEquals(['amigo'], $result);
    }

    public function test_no_distingue_mayusculas_en_el_marcador(): void
    {
        $result = $this->personalizer->resolve(['{Nombre}', '{NOMBRE}'], $this->contact('ana'));

        $this->assertEquals(['Ana', 'Ana'], $result);
    }

    public function test_deja_intactas_las_variables_sin_marcador(): void
    {
        $result = $this->personalizer->resolve(['Mazatlán', 'agosto'], $this->contact('Ana'));

        $this->assertEquals(['Mazatlán', 'agosto'], $result);
    }

    public function test_combina_texto_fijo_con_el_nombre(): void
    {
        $result = $this->personalizer->resolve(['Estimado {nombre}'], $this->contact('ana'));

        $this->assertEquals(['Estimado Ana'], $result);
    }

    /** Meta rechaza variables con saltos de línea o tabuladores. */
    public function test_limpia_saltos_de_linea(): void
    {
        $result = $this->personalizer->resolve(["Hola\n{nombre}\t"], $this->contact('Ana'));

        $this->assertEquals(['Hola Ana'], $result);
    }

    public function test_detecta_si_la_campana_usa_personalizacion(): void
    {
        $this->assertTrue($this->personalizer->usesPersonalization(['Hola {nombre}']));
        $this->assertFalse($this->personalizer->usesPersonalization(['Mazatlán']));
        $this->assertFalse($this->personalizer->usesPersonalization([]));
    }
}
