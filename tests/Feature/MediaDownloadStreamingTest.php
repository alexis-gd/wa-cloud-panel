<?php

namespace Tests\Feature;

use App\Services\WhatsApp\DeliveryReason;
use App\Services\WhatsApp\MediaStore;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La descarga de archivos baja a disco, no a memoria.
 *
 * Antes el job hacia `$response->body()`, que arma el archivo completo en una variable de PHP.
 * Un documento de 100 MB (el tope de WhatsApp) eran 100 MB de memoria en el worker; si rebasaba
 * el `memory_limit`, el proceso moria, el job reintentaba 3 veces y el archivo se perdia igual.
 *
 * Con `sink()` la respuesta se escribe directo al archivo en pedazos, asi que el tamano deja de
 * importar. Estos tests cuidan las tres cosas que eso introduce: que de verdad use un archivo,
 * que no quede basura cuando algo falla, y que el tope se revise ANTES de bajar nada.
 */
class MediaDownloadStreamingTest extends TestCase
{
    use RefreshDatabase;

    private const MEDIA_ID = 'media-abc-123';
    private const URL      = 'https://lookaside.fbsbx.com/whatsapp/archivo.jpg';

    private function client(): WhatsAppClient
    {
        return app(WhatsAppClient::class);
    }

    /** La ficha que Meta devuelve antes de la descarga. */
    private function ficha(int $tamano = 2048): array
    {
        return ['url' => self::URL, 'mime_type' => 'image/jpeg', 'file_size' => $tamano];
    }

    protected function tearDown(): void
    {
        // Los temporales son archivos de verdad (el punto del streaming): no los deja Storage
        // fake, asi que se limpian a mano para no ensuciar el repo entre corridas.
        foreach (glob(storage_path('app/tmp/media/*.part')) ?: [] as $sobrante) {
            @unlink($sobrante);
        }

        parent::tearDown();
    }

    // -- Baja a un archivo, no a memoria ---------------------------------------

    public function test_la_descarga_deja_el_archivo_en_disco_y_devuelve_su_ruta(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response($this->ficha(), 200),
            self::URL              => Http::response('contenido-de-la-foto', 200),
        ]);

        $res = $this->client()->downloadMedia(self::MEDIA_ID, 'token-falso');

        $this->assertTrue($res['ok']);
        // `path` y no `body`: el contenido nunca pasa por una variable de PHP.
        $this->assertArrayNotHasKey('body', $res);
        $this->assertFileExists($res['path']);
        $this->assertSame('contenido-de-la-foto', file_get_contents($res['path']));
    }

    public function test_el_tamano_que_reporta_es_el_real_del_archivo_no_el_que_dijo_meta(): void
    {
        // Meta dice 999 pero bajaron 20 bytes: si la descarga se corta, la ficha miente.
        Http::fake([
            'graph.facebook.com/*' => Http::response($this->ficha(999), 200),
            self::URL              => Http::response('contenido-de-la-foto', 200),
        ]);

        $res = $this->client()->downloadMedia(self::MEDIA_ID, 'token-falso');

        $this->assertSame(strlen('contenido-de-la-foto'), $res['size']);
    }

    /** El temporal vive junto a su destino para que moverlo no copie bytes. */
    public function test_el_temporal_queda_bajo_storage_y_no_en_el_temporal_del_sistema(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response($this->ficha(), 200),
            self::URL              => Http::response('x', 200),
        ]);

        $res = $this->client()->downloadMedia(self::MEDIA_ID, 'token-falso');

        $this->assertStringStartsWith(storage_path('app/tmp/media'), $res['path']);
    }

    // -- El tope se revisa antes de bajar nada ---------------------------------

    public function test_un_archivo_que_pasa_del_tope_se_rechaza_sin_descargarlo(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(
                $this->ficha(WhatsAppClient::MAX_MEDIA_BYTES + 1),
                200,
            ),
            self::URL => Http::response('no deberia pedirse', 200),
        ]);

        $res = $this->client()->downloadMedia(self::MEDIA_ID, 'token-falso');

        $this->assertFalse($res['ok']);
        $this->assertNull($res['path']);

        // Lo que de verdad importa: no se gasto ancho de banda. Meta ya habia dicho el tamano
        // en la ficha, asi que rechazarlo no cuesta nada.
        Http::assertNotSent(fn ($request) => $request->url() === self::URL);
    }

    public function test_un_archivo_justo_en_el_tope_si_se_descarga(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(
                $this->ficha(WhatsAppClient::MAX_MEDIA_BYTES),
                200,
            ),
            self::URL => Http::response('cabe', 200),
        ]);

        $this->assertTrue($this->client()->downloadMedia(self::MEDIA_ID, 'token-falso')['ok']);
    }

    /** El tope es el de Meta para documentos: nunca rechazamos algo que Meta si acepto. */
    public function test_el_tope_es_el_mismo_de_meta(): void
    {
        $this->assertSame(100 * 1024 * 1024, WhatsAppClient::MAX_MEDIA_BYTES);
    }

    // -- No deja basura en disco -----------------------------------------------

    /**
     * Con `sink` el cuerpo del error TAMBIEN se escribe en el archivo. Sin borrarlo quedaria un
     * archivo con un JSON de error adentro, que el panel serviria como si fuera la foto.
     */
    public function test_una_descarga_con_error_no_deja_el_archivo_a_medias(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response($this->ficha(), 200),
            self::URL              => Http::response(['error' => 'expired'], 404),
        ]);

        $antes = count(glob(storage_path('app/tmp/media/*.part')) ?: []);

        $res = $this->client()->downloadMedia(self::MEDIA_ID, 'token-falso');

        $this->assertFalse($res['ok']);
        $this->assertSame($antes, count(glob(storage_path('app/tmp/media/*.part')) ?: []));
    }

    public function test_si_meta_no_da_la_ficha_no_se_intenta_descargar(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => 'nope'], 400)]);

        $res = $this->client()->downloadMedia(self::MEDIA_ID, 'token-falso');

        $this->assertFalse($res['ok']);
        Http::assertNotSent(fn ($request) => $request->url() === self::URL);
    }

    // -- Guardar mueve el archivo, no lo relee ---------------------------------

    /**
     * Releer el temporal para volverlo a escribir anularia el streaming: el archivo pasaria
     * completo por la memoria justo en el ultimo paso.
     */
    public function test_guardar_mueve_el_archivo_en_vez_de_copiarlo(): void
    {
        Storage::fake(MediaStore::DISK);

        $carpeta = storage_path('app/tmp/media');
        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0775, true);
        }

        $origen = $carpeta . DIRECTORY_SEPARATOR . uniqid('mover-', true) . '.part';
        file_put_contents($origen, 'contenido');

        $ruta = app(MediaStore::class)->guardarArchivo($origen, 'image/jpeg', 7);

        Storage::disk(MediaStore::DISK)->assertExists($ruta);
        $this->assertSame('contenido', Storage::disk(MediaStore::DISK)->get($ruta));
        $this->assertFileDoesNotExist($origen);
    }

    public function test_guardar_respeta_la_carpeta_por_ano_mes_y_contacto(): void
    {
        Storage::fake(MediaStore::DISK);

        $carpeta = storage_path('app/tmp/media');
        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0775, true);
        }

        $origen = $carpeta . DIRECTORY_SEPARATOR . uniqid('ruta-', true) . '.part';
        file_put_contents($origen, 'x');

        $ruta = app(MediaStore::class)->guardarArchivo($origen, 'application/pdf', 42);

        // La carpeta por contacto permite borrar todo lo de una persona de golpe el dia que
        // ejerza su derecho de supresion.
        $this->assertStringContainsString('/' . now()->format('Y/m') . '/42/', $ruta);
        $this->assertStringEndsWith('.pdf', $ruta);
    }

    // -- La basura que ningun try/catch alcanza --------------------------------

    private function temporalConEdad(int $horasAtras): string
    {
        $carpeta = storage_path('app/tmp/media');
        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0775, true);
        }

        $ruta = $carpeta . DIRECTORY_SEPARATOR . uniqid('viejo-', true) . '.part';
        file_put_contents($ruta, 'descarga a medias');
        touch($ruta, now()->subHours($horasAtras)->getTimestamp());

        return $ruta;
    }

    /**
     * Si al worker lo matan en seco (kill -9, se acaba la memoria, reinicio del servidor), el
     * proceso desaparece sin ejecutar la limpieza y el `.part` se queda para siempre. Con
     * documentos de hasta 100 MB, unos cuantos se notan en disco.
     */
    public function test_limpia_las_descargas_interrumpidas_viejas(): void
    {
        $viejo = $this->temporalConEdad(48);

        $this->artisan('media:limpiar-temporales')->assertExitCode(0);

        $this->assertFileDoesNotExist($viejo);
    }

    /** Una descarga EN CURSO tiene su `.part` abierto ahora mismo: no se puede borrar. */
    public function test_no_toca_una_descarga_que_podria_estar_en_curso(): void
    {
        $reciente = $this->temporalConEdad(0);

        $this->artisan('media:limpiar-temporales')->assertExitCode(0);

        $this->assertFileExists($reciente);
    }

    public function test_el_corte_de_antiguedad_se_puede_ajustar(): void
    {
        $deDosHoras = $this->temporalConEdad(2);

        $this->artisan('media:limpiar-temporales --horas=1')->assertExitCode(0);

        $this->assertFileDoesNotExist($deDosHoras);
    }

    // -- El error de Meta por archivo grande, en espanol -----------------------

    public function test_el_error_131052_se_traduce_para_el_operador(): void
    {
        $texto = DeliveryReason::DELIVERY_ERRORS[131052] ?? null;

        $this->assertNotNull($texto, 'El 131052 (archivo > 100 MB) debe estar en el catálogo');
        $this->assertStringContainsString('100 MB', $texto);
        // Nunca en inglés: lo lee el operador.
        $this->assertStringNotContainsString('file', strtolower($texto));
    }
}
