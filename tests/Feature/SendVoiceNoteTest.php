<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\PhoneNumber;
use App\Services\WhatsApp\MediaStore;
use App\Services\WhatsApp\VoiceNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Notas de voz grabadas desde el panel.
 *
 * Entran por la misma ruta que los demas adjuntos, pero con el campo `voice`. Lo que se prueba
 * aqui es lo que cambia por ser una grabacion y no un archivo elegido:
 *
 * - el navegador graba en WebM, que WhatsApp no acepta, asi que hace falta ffmpeg en el servidor
 * - una grabacion no trae nombre: hay que ponerle uno con significado
 * - un `.webm` elegido a mano NO debe colarse por ese camino
 *
 * **Por que nunca se llama a ffmpeg de verdad.** `VoiceNote::disponible()` guarda su respuesta
 * en cache, asi que los tests la fijan ahi y el binario no entra en juego. La conversion en si
 * depende de una herramienta del sistema que puede no estar en la maquina de quien corre la
 * suite; probarla aqui haria que los tests fallaran por el entorno y no por el codigo.
 */
class SendVoiceNoteTest extends TestCase
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
            '*/media'    => Http::response(['id' => 'media-voz-1'], 200),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.VOZ']]], 200),
        ]);
    }

    /**
     * Hace de cuenta que el servidor tiene (o no tiene) ffmpeg, sin tocarlo.
     *
     * Cuando lo tiene, ademas se sustituye el conversor por uno que devuelve un archivo ya
     * hecho. Desde que TODA grabacion se recodifica, no hacerlo levantaria un ffmpeg de verdad
     * por cada test - y la suite dependeria de una herramienta del sistema que puede no estar
     * en la maquina de quien la corre.
     */
    private function conFfmpeg(bool $hay = true): void
    {
        Cache::put('media.ffmpeg', $hay, 3600);

        if ($hay) {
            $this->instance(VoiceNote::class, new VoiceNoteDePrueba());
        }
    }

    /** Una grabacion cualquiera. El formato de entrada da igual: todo acaba en Ogg/Opus mono. */
    private function grabacion(string $mime = 'audio/ogg'): UploadedFile
    {
        return UploadedFile::fake()->create('nota-de-voz.ogg', 120, $mime);
    }

    private function enviar(UploadedFile $archivo, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(
            "/api/conversations/{$this->contact->id}/media",
            array_merge(['file' => $archivo, 'voice' => '1'], $extra),
            ['Accept' => 'application/json'],
        );
    }

    // -- El camino feliz --------------------------------------------------------

    public function test_manda_la_nota_de_voz_y_queda_en_el_chat(): void
    {
        $this->conFfmpeg();

        $this->enviar($this->grabacion())
             ->assertStatus(201)
             ->assertJsonPath('data.message_type', 'audio');

        $this->assertDatabaseHas('conversations', [
            'contact_id'   => $this->contact->id,
            'direction'    => 'outbound',
            'message_type' => 'audio',
            'media_mime'   => 'audio/ogg',
        ]);
    }

    /**
     * El navegador entrega bytes sueltos, sin nombre. Sin este, la nota se descargaba con el
     * identificador interno que inventa el reproductor - un nombre que no le dice nada a nadie.
     */
    public function test_la_grabacion_se_guarda_con_un_nombre_que_significa_algo(): void
    {
        $this->conFfmpeg();

        $this->enviar($this->grabacion())->assertStatus(201);

        $this->assertDatabaseHas('conversations', [
            'direction'      => 'outbound',
            'media_filename' => VoiceNote::NOMBRE,
        ]);
    }

    /**
     * `voice: true` es lo unico que separa una nota de voz de un archivo de audio.
     *
     * Sin ese campo Meta entrega la grabacion como adjunto descargable, con icono de
     * audifonos, en vez de la burbuja con onda y boton de reproducir. Paso en produccion: la
     * primera version no lo mandaba y las notas llegaban como archivos.
     */
    public function test_viaja_marcada_como_nota_de_voz(): void
    {
        $this->conFfmpeg();

        $this->enviar($this->grabacion())->assertStatus(201);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/messages')
            && data_get($r->data(), 'audio.voice') === true);
    }

    /** Una nota de voz con texto no existe en WhatsApp: ese pie nunca le llegaria al contacto. */
    public function test_la_nota_de_voz_no_lleva_pie_de_texto(): void
    {
        $this->conFfmpeg();

        $this->enviar($this->grabacion(), ['caption' => 'Escuche esto'])->assertStatus(201);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/messages')
            && data_get($r->data(), 'audio.caption') === null);

        $this->assertDatabaseHas('conversations', [
            'direction'    => 'outbound',
            'message_type' => 'audio',
            'body'         => '',
        ]);
    }

    /**
     * Firefox y Chrome nombran el mismo Ogg de maneras distintas, y quien adivina el formato
     * por el contenido tiene su propia opinion. Ninguna de esas variantes debe perderse.
     *
     * @dataProvider formatosQueYaEstanListos
     */
    public function test_acepta_la_grabacion_la_nombre_como_la_nombre(string $mime): void
    {
        $this->conFfmpeg();

        $this->enviar($this->grabacion($mime))
             ->assertStatus(201)
             ->assertJsonPath('data.message_type', 'audio');
    }

    public static function formatosQueYaEstanListos(): array
    {
        return [
            'audio/ogg'        => ['audio/ogg'],
            'application/ogg'  => ['application/ogg'],
            'audio/opus'       => ['audio/opus'],
            'audio/x-opus+ogg' => ['audio/x-opus+ogg'],
            'audio/mp4 (Safari)' => ['audio/mp4'],
        ];
    }

    // -- Lo que protege ---------------------------------------------------------

    /**
     * Sin ffmpeg no hay forma de convertir lo que graba el navegador. El panel ya esconde el
     * microfono en ese caso, pero la regla vive tambien aqui: el panel es lo que se puede
     * cambiar desde el navegador, el servidor no.
     */
    public function test_sin_ffmpeg_no_se_manda_nada(): void
    {
        $this->conFfmpeg(false);

        $this->enviar($this->grabacion())
             ->assertStatus(422)
             ->assertJsonPath('code', 'VOICE_UNAVAILABLE');

        Http::assertNothingSent();
    }

    /**
     * El camino de la grabacion acepta WebM, que el de los archivos rechaza. Si el campo `voice`
     * no bastara para distinguirlos, un `.webm` elegido a mano entraria por la puerta de atras.
     */
    public function test_un_webm_elegido_a_mano_sigue_sin_poder_mandarse(): void
    {
        $this->conFfmpeg();

        $this->post(
            "/api/conversations/{$this->contact->id}/media",
            ['file' => UploadedFile::fake()->create('video.webm', 300, 'video/webm')],
            ['Accept' => 'application/json'],
        )->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_rechaza_una_grabacion_que_pasa_del_tope_de_whatsapp(): void
    {
        $this->conFfmpeg();

        $respuesta = $this->enviar(UploadedFile::fake()->create('larga.ogg', 20 * 1024, 'audio/ogg'))
                          ->assertStatus(422)
                          ->assertJsonPath('code', 'FILE_NOT_SUPPORTED');

        $this->assertStringContainsString('16 MB', $respuesta->json('message'));

        Http::assertNothingSent();
    }

    /** Las reglas de envio son las mismas que para el texto: una baja bloquea todos los caminos. */
    public function test_no_le_manda_una_nota_de_voz_a_quien_se_dio_de_baja(): void
    {
        $this->conFfmpeg();
        $this->contact->update(['status' => 'opted_out']);

        $this->enviar($this->grabacion())
             ->assertStatus(422)
             ->assertJsonPath('code', 'OPTED_OUT');

        Http::assertNothingSent();
    }

    /**
     * El diagnostico tiene que poder correrse en cualquier maquina sin reventar: es justo la
     * herramienta a la que se recurre cuando algo no funciona.
     */
    public function test_el_diagnostico_dice_claro_cuando_no_hay_ffmpeg(): void
    {
        config(['media.ffmpeg' => '']);

        $this->artisan('wa:probar-nota-de-voz')
             ->expectsOutputToContain('FFMPEG_PATH esta vacio')
             ->assertFailed();
    }

    // -- Lo que el panel necesita saber -----------------------------------------

    /**
     * El panel pregunta al abrir el chat si puede ofrecer el microfono. Sin este dato lo
     * ofreceria siempre, y el envio fallaria cuando el operador ya grabo.
     */
    public function test_el_chat_dice_si_el_servidor_puede_mandar_notas_de_voz(): void
    {
        $this->conFfmpeg();

        $this->getJson("/api/conversations/{$this->contact->id}")
             ->assertStatus(200)
             ->assertJsonPath('data.voice_notes', true);

        $this->conFfmpeg(false);

        $this->getJson("/api/conversations/{$this->contact->id}")
             ->assertStatus(200)
             ->assertJsonPath('data.voice_notes', false);
    }
}


/**
 * Conversor de mentiras: entrega un Ogg ya hecho sin levantar ffmpeg.
 *
 * Lo que se prueba en este archivo es la ruta HTTP - que acepte, que rechace, con que nombre
 * guarda y como viaja a Meta. La conversion en si depende de una herramienta del sistema y se
 * verifica a mano al desplegar, no aqui.
 */
class VoiceNoteDePrueba extends VoiceNote
{
    public function preparar(UploadedFile $archivo): array
    {
        $pesa = $archivo->getSize();

        // El tope si se respeta: es una regla del negocio, no de la conversion.
        if ($pesa !== false && $pesa > self::MAX_BYTES) {
            return ['ok' => false, 'path' => null, 'mime' => null, 'tipo' => null,
                    'temporal' => false, 'error' => 'La nota de voz pasa del maximo que acepta WhatsApp (16 MB).'];
        }

        $salida = tempnam(sys_get_temp_dir(), 'voz-de-prueba-');
        file_put_contents($salida, 'ogg de mentiras');

        return ['ok' => true, 'path' => $salida, 'mime' => 'audio/ogg', 'tipo' => 'audio',
                'temporal' => true, 'error' => null];
    }
}
