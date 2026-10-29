<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\PhoneNumber;
use App\Services\WhatsApp\MediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El comando que prueba los archivos contra Meta sin desplegar.
 *
 * Aqui la API va simulada - el valor del comando es justo correrlo contra la API de verdad.
 * Lo que estos tests cuidan es que el comando no haga danos: que no fabrique historial falso en
 * produccion, que no deje temporales tirados cuando algo falla, y que avise claro en vez de
 * reventar cuando falta algo.
 */
class TestWhatsAppMediaCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $archivo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MediaStore::DISK);

        $this->archivo = storage_path('app/tmp/prueba-' . uniqid() . '.jpg');

        if (! is_dir(dirname($this->archivo))) {
            mkdir(dirname($this->archivo), 0775, true);
        }

        file_put_contents($this->archivo, 'contenido-de-prueba');
    }

    protected function tearDown(): void
    {
        @unlink($this->archivo);

        foreach (glob(storage_path('app/tmp/media/*.part')) ?: [] as $sobrante) {
            @unlink($sobrante);
        }

        parent::tearDown();
    }

    private function numeroActivo(): void
    {
        PhoneNumber::factory()->create(['is_active' => true]);
    }

    /** Subida OK, ficha OK, descarga OK. */
    private function metaResponde(): void
    {
        Http::fake([
            // La subida devuelve el id.
            '*/media' => Http::response(['id' => 'media-subido-1'], 200),
            // La ficha del archivo.
            '*/media-subido-1*' => Http::response([
                'url'       => 'https://lookaside.fbsbx.com/x.jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => 19,
            ], 200),
            'lookaside.fbsbx.com/*' => Http::response('contenido-de-prueba', 200),
        ]);
    }

    // -- Lo que no debe hacer --------------------------------------------------

    /**
     * El caso peligroso: `--contacto` crea un mensaje ENTRANTE de prueba. En produccion eso
     * seria historial inventado en las conversaciones del cliente, con su asignacion automatica
     * y su aviso en la campanita.
     */
    public function test_no_fabrica_mensajes_de_prueba_en_produccion(): void
    {
        $this->app['env'] = 'production';

        $this->numeroActivo();
        $this->metaResponde();

        $contacto = Contact::factory()->create(['phone' => '529231311146']);

        $this->artisan('wa:probar-media', [
            'archivo'     => $this->archivo,
            '--contacto'  => '529231311146',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_sin_contacto_no_deja_el_temporal_tirado(): void
    {
        $this->numeroActivo();
        $this->metaResponde();

        $antes = count(glob(storage_path('app/tmp/media/*.part')) ?: []);

        $this->artisan('wa:probar-media', ['archivo' => $this->archivo])->assertExitCode(0);

        $this->assertSame($antes, count(glob(storage_path('app/tmp/media/*.part')) ?: []));
    }

    public function test_si_el_contacto_no_existe_no_deja_el_temporal_tirado(): void
    {
        $this->numeroActivo();
        $this->metaResponde();

        $antes = count(glob(storage_path('app/tmp/media/*.part')) ?: []);

        $this->artisan('wa:probar-media', [
            'archivo'    => $this->archivo,
            '--contacto' => '520000000000',
        ])->assertExitCode(1);

        $this->assertSame($antes, count(glob(storage_path('app/tmp/media/*.part')) ?: []));
    }

    // -- Lo que debe avisar en vez de reventar ---------------------------------

    public function test_avisa_si_el_archivo_no_existe(): void
    {
        $this->numeroActivo();

        $this->artisan('wa:probar-media', ['archivo' => '/ruta/que/no/existe.jpg'])
             ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_avisa_si_no_hay_numero_con_token(): void
    {
        $this->artisan('wa:probar-media', ['archivo' => $this->archivo])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_avisa_si_meta_rechaza_la_subida(): void
    {
        $this->numeroActivo();

        Http::fake([
            '*/media' => Http::response(['error' => ['message' => 'Archivo no soportado']], 400),
        ]);

        $this->artisan('wa:probar-media', ['archivo' => $this->archivo])->assertExitCode(1);
    }

    public function test_avisa_si_la_descarga_falla(): void
    {
        $this->numeroActivo();

        Http::fake([
            '*/media'           => Http::response(['id' => 'media-subido-1'], 200),
            '*/media-subido-1*' => Http::response(['error' => 'sin ficha'], 400),
        ]);

        $this->artisan('wa:probar-media', ['archivo' => $this->archivo])->assertExitCode(1);
    }

    // -- Lo que debe lograr ----------------------------------------------------

    public function test_con_contacto_deja_una_burbuja_visible_en_el_panel(): void
    {
        $this->numeroActivo();
        $this->metaResponde();

        $contacto = Contact::factory()->create(['phone' => '529231311146']);

        $this->artisan('wa:probar-media', [
            'archivo'    => $this->archivo,
            '--contacto' => '529231311146',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('conversations', [
            'contact_id'   => $contacto->id,
            'direction'    => 'inbound',
            'message_type' => 'image',
            // `stored` y no `pending`: se guarda directo para no depender del worker.
            'media_status' => 'stored',
        ]);
    }
}
