<?php

namespace Tests\Feature;

use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Importación de contactos desde archivo. El teléfono se normaliza ANTES de comparar, así que
 * el mismo número escrito de distintas formas cuenta como uno solo.
 */
class ContactImportTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('contactos.csv', $content);
    }

    private function import(UploadedFile $file)
    {
        return $this->actingAsAdmin()
                    ->post('/api/contacts/upload', ['file' => $file], ['Accept' => 'application/json']);
    }

    /** Regresión: `IOFactory::load()` recibía la clase del lector donde va un int -> TypeError 500. */
    public function test_importa_un_csv(): void
    {
        $this->import($this->csv("telefono,nombre\n6692522844,Joseph\n"))
             ->assertStatus(200)
             ->assertJsonPath('summary.inserted', 1);

        $this->assertDatabaseHas('contacts', ['phone' => '526692522844', 'name' => 'Joseph']);
    }

    public function test_el_mismo_numero_en_distintos_formatos_entra_una_sola_vez(): void
    {
        $response = $this->import($this->csv(
            "telefono,nombre\n6692522844,Uno\n526692522844,Dos\n(669) 252-2844,Tres\n5216692522844,Cuatro\n"
        ))->assertStatus(200);

        $this->assertEquals(1, $response->json('summary.inserted'));
        $this->assertEquals(3, $response->json('summary.duplicates'));
        $this->assertEquals(1, Contact::count());
    }

    public function test_no_reimporta_un_numero_que_ya_existe(): void
    {
        Contact::factory()->create(['phone' => '526692522844']);

        $this->import($this->csv("telefono,nombre\n6692522844,Joseph\n"))
             ->assertStatus(200)
             ->assertJsonPath('summary.inserted', 0)
             ->assertJsonPath('summary.duplicates', 1);
    }

    /**
     * Regresión: `phone` tiene índice UNIQUE y el borrado es soft, así que un número borrado
     * seguía ocupando el lugar. La consulta ignoraba los borrados -> intentaba insertar ->
     * error de llave duplicada -> 500 y la importación entera se caía.
     */
    public function test_un_numero_borrado_no_tumba_la_importacion(): void
    {
        Contact::factory()->create(['phone' => '526692522844'])->delete();

        $this->import($this->csv("telefono,nombre\n6692522844,Joseph\n9231311146,Ana\n"))
             ->assertStatus(200)
             ->assertJsonPath('summary.duplicates', 1)
             ->assertJsonPath('summary.inserted', 1);

        // El resto del archivo se importó: un borrado no puede cortar el proceso.
        $this->assertDatabaseHas('contacts', ['phone' => '529231311146']);
    }

    public function test_reporta_los_numeros_invalidos_sin_detenerse(): void
    {
        $response = $this->import($this->csv(
            "telefono,nombre\n6692522844,Bueno\n123,Malo\nhola,Peor\n9231311146,Bueno2\n"
        ))->assertStatus(200);

        $this->assertEquals(2, $response->json('summary.inserted'));
        $this->assertEquals(2, $response->json('summary.invalid'));
        $this->assertNotEmpty($response->json('summary.errors'));
    }

    public function test_detecta_el_encabezado_sin_importar_el_orden(): void
    {
        $this->import($this->csv("nombre,celular\nJoseph,6692522844\n"))
             ->assertStatus(200)
             ->assertJsonPath('summary.inserted', 1);

        $this->assertDatabaseHas('contacts', ['phone' => '526692522844', 'name' => 'Joseph']);
    }

    public function test_funciona_sin_encabezado(): void
    {
        $this->import($this->csv("6692522844,Joseph\n"))
             ->assertStatus(200)
             ->assertJsonPath('summary.inserted', 1);
    }
}
