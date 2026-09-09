<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * `guias:build` - Markdown a HTML estilizado.
 *
 * Además de las dos guías del cliente, convierte cualquier Markdown suelto. Existe para eso
 * porque la plantilla ya trae estilos de impresión y un botón de "Guardar PDF": entregar un
 * documento en PDF no necesita instalar nada, y sale con el mismo formato que las guías.
 */
class GuiasBuildTest extends TestCase
{
    private string $md;
    private string $salida;

    protected function setUp(): void
    {
        parent::setUp();

        $this->md     = base_path('tests-tmp-doc.md');
        $this->salida = public_path('doc/tests-tmp-doc.html');
    }

    protected function tearDown(): void
    {
        File::delete($this->md);
        File::delete($this->salida);
        File::delete(public_path('doc/otro-nombre.html'));

        parent::tearDown();
    }

    private function escribirMd(string $contenido): void
    {
        File::put($this->md, $contenido);
    }

    public function test_convierte_un_markdown_suelto(): void
    {
        $this->escribirMd("# Mi documento\n\nUn párrafo.\n\n## Una sección\n\nOtro párrafo.\n");

        $this->artisan('guias:build', ['fuente' => 'tests-tmp-doc.md'])
             ->assertSuccessful();

        $this->assertTrue(File::exists($this->salida));

        $html = File::get($this->salida);
        $this->assertStringContainsString('<title>Mi documento</title>', $html);
        $this->assertStringContainsString('Una sección', $html);
    }

    public function test_el_html_trae_el_boton_de_guardar_pdf(): void
    {
        // Es el punto del comando: que quien lo abra pueda sacar el PDF sin instalar nada.
        $this->escribirMd("# Doc\n\nContenido.\n");

        $this->artisan('guias:build', ['fuente' => 'tests-tmp-doc.md']);

        $this->assertStringContainsString('print-btn', File::get($this->salida));
    }

    public function test_arma_indice_con_los_encabezados(): void
    {
        $this->escribirMd("# Doc\n\n## Primera\n\nTexto.\n\n## Segunda\n\nTexto.\n");

        $this->artisan('guias:build', ['fuente' => 'tests-tmp-doc.md']);

        $html = File::get($this->salida);
        $this->assertStringContainsString('Primera', $html);
        $this->assertStringContainsString('Segunda', $html);
    }

    public function test_se_puede_elegir_el_nombre_de_salida(): void
    {
        $this->escribirMd("# Doc\n\nContenido.\n");

        $this->artisan('guias:build', [
            'fuente'   => 'tests-tmp-doc.md',
            '--salida' => 'doc/otro-nombre.html',
        ])->assertSuccessful();

        $this->assertTrue(File::exists(public_path('doc/otro-nombre.html')));
    }

    public function test_avisa_si_el_archivo_no_existe(): void
    {
        $this->artisan('guias:build', ['fuente' => 'no-existe-este-archivo.md'])
             ->expectsOutputToContain('No encontré el archivo')
             ->assertFailed();
    }

    public function test_sin_argumento_sigue_armando_las_guias_del_cliente(): void
    {
        // El comportamiento de siempre no se toca: `php artisan guias:build` a secas es lo
        // que corre en cada cambio de la guía del operador.
        $this->artisan('guias:build')->assertSuccessful();

        $this->assertTrue(File::exists(public_path('guia/uso.html')));
        $this->assertTrue(File::exists(public_path('guia/meta.html')));
    }
}
