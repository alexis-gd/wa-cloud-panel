<?php

namespace Tests\Feature;

use App\Jobs\DownloadWhatsAppMedia;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\PhoneNumber;
use App\Services\WhatsApp\MediaStore;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Archivos que manda el contacto.
 *
 * Antes de esto, una foto caia en el `default` del match del webhook y se guardaba como un
 * mensaje de TEXTO VACIO: el operador veia una burbuja en blanco y el archivo se perdia. En un
 * negocio de prestamos eso son INE y comprobantes de domicilio que nadie recibio.
 */
class InboundMediaTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contact = Contact::factory()->create([
            'phone'  => '529231311146',
            'status' => 'active',
        ]);

        PhoneNumber::factory()->create(['is_active' => true]);
    }

    private function postWebhook(array $body): \Illuminate\Testing\TestResponse
    {
        $content   = json_encode($body);
        $secret    = config('services.whatsapp.app_secret', 'test_secret');
        $signature = 'sha256=' . hash_hmac('sha256', $content, $secret);

        return $this->postJson('/api/webhook', $body, ['X-Hub-Signature-256' => $signature]);
    }

    private function mediaPayload(string $type, array $datos): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => ['messages' => [[
                        'from' => '529231311146',
                        'id'   => 'wamid.media.test',
                        'type' => $type,
                        $type  => $datos,
                    ]]],
                ]],
            ]],
        ];
    }

    // -- El webhook ya no tira los archivos ------------------------------------

    public function test_una_foto_se_guarda_como_imagen_y_no_como_texto_vacio(): void
    {
        Bus::fake();

        $this->postWebhook($this->mediaPayload('image', [
            'id'        => 'media-abc-123',
            'mime_type' => 'image/jpeg',
        ]))->assertStatus(200);

        $this->assertDatabaseHas('conversations', [
            'contact_id'   => $this->contact->id,
            'message_type' => 'image',
            'media_id'     => 'media-abc-123',
            'media_mime'   => 'image/jpeg',
            'media_status' => 'pending',
        ]);
    }

    public function test_el_pie_de_foto_se_guarda_como_cuerpo_del_mensaje(): void
    {
        Bus::fake();

        $this->postWebhook($this->mediaPayload('image', [
            'id'        => 'media-abc-123',
            'mime_type' => 'image/jpeg',
            'caption'   => 'Aqui esta mi INE',
        ]))->assertStatus(200);

        $this->assertDatabaseHas('conversations', ['body' => 'Aqui esta mi INE']);
    }

    public function test_un_documento_conserva_el_nombre_con_el_que_lo_mandaron(): void
    {
        Bus::fake();

        $this->postWebhook($this->mediaPayload('document', [
            'id'        => 'media-doc-1',
            'mime_type' => 'application/pdf',
            'filename'  => 'comprobante domicilio.pdf',
        ]))->assertStatus(200);

        $this->assertDatabaseHas('conversations', [
            'message_type'   => 'document',
            'media_filename' => 'comprobante domicilio.pdf',
        ]);
    }

    /** Un sticker es una imagen para fines practicos: se ve igual y se guarda igual. */
    public function test_un_sticker_entra_como_imagen(): void
    {
        Bus::fake();

        $this->postWebhook($this->mediaPayload('sticker', [
            'id'        => 'media-sticker-1',
            'mime_type' => 'image/webp',
        ]))->assertStatus(200);

        $this->assertDatabaseHas('conversations', ['message_type' => 'image']);
    }

    public function test_una_nota_de_voz_y_un_video_se_reconocen(): void
    {
        Bus::fake();

        $this->postWebhook($this->mediaPayload('audio', [
            'id' => 'media-audio-1', 'mime_type' => 'audio/ogg',
        ]))->assertStatus(200);

        $this->postWebhook($this->mediaPayload('video', [
            'id' => 'media-video-1', 'mime_type' => 'video/mp4',
        ]))->assertStatus(200);

        $this->assertDatabaseHas('conversations', ['message_type' => 'audio']);
        $this->assertDatabaseHas('conversations', ['message_type' => 'video']);
    }

    // -- La descarga va en cola -----------------------------------------------

    public function test_recibir_un_archivo_encola_su_descarga(): void
    {
        Bus::fake();

        $this->postWebhook($this->mediaPayload('image', [
            'id' => 'media-abc-123', 'mime_type' => 'image/jpeg',
        ]))->assertStatus(200);

        Bus::assertDispatched(DownloadWhatsAppMedia::class);
    }

    /** Un mensaje de texto no tiene nada que descargar. */
    public function test_un_mensaje_de_texto_no_encola_ninguna_descarga(): void
    {
        Bus::fake();

        $this->postWebhook([
            'entry' => [[
                'changes' => [[
                    'value' => ['messages' => [[
                        'from' => '529231311146',
                        'id'   => 'wamid.texto',
                        'type' => 'text',
                        'text' => ['body' => 'Hola'],
                    ]]],
                ]],
            ]],
        ])->assertStatus(200);

        Bus::assertNotDispatched(DownloadWhatsAppMedia::class);
    }

    // -- El job que baja el archivo -------------------------------------------

    private function conversacionPendiente(string $mime = 'image/jpeg'): Conversation
    {
        return Conversation::create([
            'contact_id'   => $this->contact->id,
            'direction'    => 'inbound',
            'message_type' => 'image',
            'body'         => '',
            'status'       => 'received',
            'window_open'  => true,
            'media_id'     => 'media-abc-123',
            'media_mime'   => $mime,
            'media_status' => 'pending',
        ]);
    }

    public function test_el_job_baja_el_archivo_y_lo_deja_en_disco_privado(): void
    {
        Storage::fake(MediaStore::DISK);

        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldReceive('downloadMedia')->once()->andReturn([
                'ok'    => true,
                'body'  => 'contenido-binario-de-la-foto',
                'mime'  => 'image/jpeg',
                'size'  => 28,
                'error' => null,
            ]);
        });

        $conversation = $this->conversacionPendiente();

        (new DownloadWhatsAppMedia($conversation->id))->handle(
            app(WhatsAppClient::class),
            app(MediaStore::class),
        );

        $conversation->refresh();

        $this->assertSame('stored', $conversation->media_status);
        $this->assertNotNull($conversation->media_path);
        Storage::disk(MediaStore::DISK)->assertExists($conversation->media_path);
    }

    /** El nombre en disco lo inventamos nosotros: el de afuera podria traer `../`. */
    public function test_el_archivo_no_se_guarda_con_el_nombre_que_mando_el_contacto(): void
    {
        Storage::fake(MediaStore::DISK);

        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldReceive('downloadMedia')->andReturn([
                'ok' => true, 'body' => 'x', 'mime' => 'application/pdf', 'size' => 1, 'error' => null,
            ]);
        });

        $conversation = $this->conversacionPendiente('application/pdf');
        $conversation->update(['media_filename' => '../../.env']);

        (new DownloadWhatsAppMedia($conversation->id))->handle(
            app(WhatsAppClient::class),
            app(MediaStore::class),
        );

        $ruta = $conversation->refresh()->media_path;

        $this->assertStringNotContainsString('..', $ruta);
        $this->assertStringStartsWith('media/', $ruta);
        $this->assertStringEndsWith('.pdf', $ruta);
    }

    public function test_un_archivo_ya_descargado_no_se_vuelve_a_bajar(): void
    {
        Storage::fake(MediaStore::DISK);

        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldNotReceive('downloadMedia');
        });

        $conversation = $this->conversacionPendiente();
        $conversation->update(['media_status' => 'stored', 'media_path' => 'media/2026/10/1/x.jpg']);

        (new DownloadWhatsAppMedia($conversation->id))->handle(
            app(WhatsAppClient::class),
            app(MediaStore::class),
        );

        $this->assertSame('stored', $conversation->refresh()->media_status);
    }

    /** Si Meta falla, el job revienta para que la cola reintente con una URL fresca. */
    public function test_si_la_descarga_falla_el_job_lanza_excepcion_para_reintentar(): void
    {
        Storage::fake(MediaStore::DISK);

        $this->mock(WhatsAppClient::class, function ($mock) {
            $mock->shouldReceive('downloadMedia')->andReturn([
                'ok' => false, 'body' => null, 'mime' => null, 'size' => null,
                'error' => 'La descarga respondio con error',
            ]);
        });

        $conversation = $this->conversacionPendiente();

        $this->expectException(\RuntimeException::class);

        (new DownloadWhatsAppMedia($conversation->id))->handle(
            app(WhatsAppClient::class),
            app(MediaStore::class),
        );
    }

    /** Agotados los intentos, queda marcado: el operador tiene que saber que no va a llegar. */
    public function test_al_agotarse_los_intentos_el_archivo_queda_marcado_como_fallido(): void
    {
        $conversation = $this->conversacionPendiente();

        (new DownloadWhatsAppMedia($conversation->id))->failed(new \RuntimeException('se acabo'));

        $this->assertSame('failed', $conversation->refresh()->media_status);
    }
}
