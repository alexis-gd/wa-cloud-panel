<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\PhoneNumber;
use App\Services\WhatsApp\MediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Mandar documentos, videos y audios desde Conversaciones.
 *
 * Las reglas de ENVIO (baja, ventana de 24h, numero pausado, agente ajeno) ya estan cubiertas
 * en `SendImageTest`: las comparte el mismo guardia y no se repiten aqui. Lo que se prueba en
 * este archivo es lo que SI cambia por tipo, que es donde Meta es quisquillosa:
 *
 * - cada tipo tiene su tope y son muy distintos entre si (5, 16 y 100 MB)
 * - el documento lleva nombre de archivo dentro del mensaje; los demas no
 * - el audio NO admite pie de texto
 */
class SendFileTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MediaStore::DISK);

        $this->contact = Contact::factory()->create(['phone' => '529231311146', 'status' => 'active']);
        PhoneNumber::factory()->create(['is_active' => true]);

        Conversation::create([
            'contact_id'   => $this->contact->id,
            'direction'    => 'inbound',
            'message_type' => 'text',
            'body'         => 'Hola',
            'status'       => 'received',
            'created_at'   => now()->subHour(),
        ]);

        $this->actingAsOperator();

        Http::fake([
            '*/media'    => Http::response(['id' => 'media-subido-1'], 200),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200),
        ]);
    }

    private function enviar(UploadedFile $archivo, ?string $caption = null): \Illuminate\Testing\TestResponse
    {
        return $this->post(
            "/api/conversations/{$this->contact->id}/media",
            array_filter(['file' => $archivo, 'caption' => $caption]),
            ['Accept' => 'application/json'],
        );
    }

    private function pdf(string $nombre = 'comprobante.pdf', int $kb = 500): UploadedFile
    {
        return UploadedFile::fake()->create($nombre, $kb, 'application/pdf');
    }

    // -- Documentos ------------------------------------------------------------

    public function test_manda_un_pdf_y_queda_en_el_chat(): void
    {
        $this->enviar($this->pdf())
             ->assertStatus(201)
             ->assertJsonPath('data.message_type', 'document');

        $this->assertDatabaseHas('conversations', [
            'contact_id'     => $this->contact->id,
            'direction'      => 'outbound',
            'message_type'   => 'document',
            'media_filename' => 'comprobante.pdf',
        ]);
    }

    /**
     * El nombre viaja DENTRO del mensaje, no solo en nuestra base. Sin el, el contacto recibe
     * un archivo con un nombre inventado por WhatsApp y no sabe que le mandaron.
     */
    public function test_el_documento_viaja_con_su_nombre(): void
    {
        $this->enviar($this->pdf('estado-de-cuenta.pdf'))->assertStatus(201);

        Http::assertSent(function ($r) {
            return str_contains($r->url(), '/messages')
                && data_get($r->data(), 'document.filename') === 'estado-de-cuenta.pdf';
        });
    }

    public function test_el_documento_admite_pie_de_texto(): void
    {
        $this->enviar($this->pdf(), 'Aquí está su comprobante')->assertStatus(201);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/messages')
            && data_get($r->data(), 'document.caption') === 'Aquí está su comprobante');
    }

    // -- Video -----------------------------------------------------------------

    public function test_manda_un_video(): void
    {
        $this->enviar(UploadedFile::fake()->create('clip.mp4', 2000, 'video/mp4'))
             ->assertStatus(201)
             ->assertJsonPath('data.message_type', 'video');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/messages')
            && data_get($r->data(), 'type') === 'video');
    }

    /**
     * Un video no se puede encoger sin transcodificarlo, asi que lo unico honesto es decirlo
     * antes de subir nada. El mensaje nombra el tope para que el operador sepa que hacer.
     */
    public function test_avisa_si_el_video_pasa_del_tope_de_whatsapp(): void
    {
        $respuesta = $this->enviar(UploadedFile::fake()->create('largo.mp4', 20 * 1024, 'video/mp4'))
                          ->assertStatus(422)
                          ->assertJsonPath('code', 'FILE_NOT_SUPPORTED');

        $this->assertStringContainsString('16 MB', $respuesta->json('message'));

        Http::assertNothingSent();
    }

    // -- Audio -----------------------------------------------------------------

    public function test_manda_un_audio(): void
    {
        $this->enviar(UploadedFile::fake()->create('nota.mp3', 300, 'audio/mpeg'))
             ->assertStatus(201)
             ->assertJsonPath('data.message_type', 'audio');
    }

    /**
     * Una nota de voz con texto no existe en WhatsApp. Si se guardara el pie, el chat mostraria
     * al operador un texto que el contacto nunca recibio.
     */
    public function test_el_audio_no_lleva_pie_ni_lo_guarda(): void
    {
        $this->enviar(UploadedFile::fake()->create('nota.mp3', 300, 'audio/mpeg'), 'Escuche esto')
             ->assertStatus(201);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/messages')
            && data_get($r->data(), 'audio.caption') === null);

        $this->assertDatabaseHas('conversations', [
            'direction'    => 'outbound',
            'message_type' => 'audio',
            'body'         => '',
        ]);
    }

    /**
     * Reenviar la nota de voz que el contacto acaba de mandar.
     *
     * Un `.ogg` no tiene un nombre de formato unico: segun lo que lleve dentro, el sistema lo
     * reporta como `audio/ogg`, `application/ogg`, `audio/opus` o varios mas. La lista solo
     * tenia el primero, asi que el panel rechazaba un archivo que el mismo acababa de entregar.
     *
     * @dataProvider nombresDeLaFamiliaOgg
     */
    public function test_acepta_la_nota_de_voz_la_nombre_como_la_nombre(string $mime): void
    {
        $this->enviar(UploadedFile::fake()->create('nota.ogg', 200, $mime))
             ->assertStatus(201)
             ->assertJsonPath('data.message_type', 'audio');
    }

    public static function nombresDeLaFamiliaOgg(): array
    {
        return [
            'audio/ogg'        => ['audio/ogg'],
            'application/ogg'  => ['application/ogg'],
            'audio/opus'       => ['audio/opus'],
            'audio/x-opus+ogg' => ['audio/x-opus+ogg'],
        ];
    }

    /**
     * Meta acepta exactamente `audio/ogg`. Subirlo con el nombre que adivino el sistema seria
     * que lo rechace por un detalle de nomenclatura, no por el archivo.
     */
    public function test_le_dice_a_meta_el_nombre_de_formato_que_espera(): void
    {
        $this->enviar(UploadedFile::fake()->create('nota.ogg', 200, 'application/ogg'))
             ->assertStatus(201);

        Http::assertSent(function ($r) {
            return str_contains($r->url(), '/media')
                && str_contains((string) $r->body(), 'audio/ogg');
        });
    }

    // -- Lo que no se puede mandar ---------------------------------------------

    public function test_rechaza_un_formato_que_whatsapp_no_acepta(): void
    {
        $this->enviar(UploadedFile::fake()->create('script.zip', 100, 'application/zip'))
             ->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * El caso peligroso del lado de salida: un SVG es un documento para quien lo sube, pero
     * WhatsApp no lo acepta y a nosotros no nos interesa empezar a moverlo.
     */
    public function test_rechaza_un_svg(): void
    {
        $this->enviar(UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'))
             ->assertStatus(422);

        Http::assertNothingSent();
    }
}
