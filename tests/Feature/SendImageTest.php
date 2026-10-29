<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Services\WhatsApp\MediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mandar una imagen al contacto desde Conversaciones.
 *
 * Lo que mas se cuida aqui no es que la imagen salga, sino que **no salga cuando no debe**:
 * una foto a alguien que se dio de baja es igual de grave que un texto, y el envio es el
 * camino por donde algo llega de verdad al telefono de una persona.
 */
class SendImageTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contact;
    private PhoneNumber $phone;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MediaStore::DISK);

        $this->contact = Contact::factory()->create(['phone' => '529231311146', 'status' => 'active']);
        $this->phone   = PhoneNumber::factory()->create(['is_active' => true]);

        $this->abrirVentana();
    }

    /** La ventana de 24h: sin un mensaje reciente del contacto, Meta solo acepta plantillas. */
    private function abrirVentana(): void
    {
        Conversation::create([
            'contact_id'   => $this->contact->id,
            'direction'    => 'inbound',
            'message_type' => 'text',
            'body'         => 'Hola',
            'status'       => 'received',
            'created_at'   => now()->subHour(),
        ]);
    }

    private function metaAcepta(): void
    {
        Http::fake([
            '*/media'    => Http::response(['id' => 'media-subido-1'], 200),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.TEST']]], 200),
        ]);
    }

    /** Una foto de verdad: 2400x1800 pasa del lado maximo, asi que se reduce. */
    private function foto(string $nombre = 'foto.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($nombre, 2400, 1800);
    }

    /**
     * `Accept: application/json` como lo manda el panel de verdad.
     *
     * Sin ese encabezado, Laravel responde los errores de validacion con un redirect de
     * formulario web (302) en vez de 422 JSON, y el panel mostraria "error del servidor". El
     * cliente lo manda en todas las subidas multipart; el test tiene que hacer lo mismo o
     * estaria probando una peticion que nadie hace.
     */
    private function enviar(array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->post(
            "/api/conversations/{$this->contact->id}/media",
            array_merge(['file' => $this->foto()], $extra),
            ['Accept' => 'application/json'],
        );
    }

    // -- El camino feliz -------------------------------------------------------

    public function test_manda_la_imagen_y_la_deja_en_el_chat(): void
    {
        $this->actingAsOperator();
        $this->metaAcepta();

        $this->enviar()
             ->assertStatus(201)
             ->assertJsonPath('status', 'ok')
             ->assertJsonPath('data.message_type', 'image')
             ->assertJsonPath('data.media_status', 'stored');

        $this->assertDatabaseHas('conversations', [
            'contact_id'   => $this->contact->id,
            'direction'    => 'outbound',
            'message_type' => 'image',
            'status'       => 'sent',
            'media_status' => 'stored',
        ]);
    }

    /** Se guarda copia propia: sin eso el chat tendria un hueco donde el operador mando algo. */
    public function test_guarda_una_copia_para_que_la_burbuja_la_muestre(): void
    {
        $this->actingAsOperator();
        $this->metaAcepta();

        $this->enviar()->assertStatus(201);

        $conversation = Conversation::where('direction', 'outbound')->first();

        $this->assertNotNull($conversation->media_path);
        Storage::disk(MediaStore::DISK)->assertExists($conversation->media_path);
    }

    public function test_el_texto_del_operador_viaja_como_pie_de_foto(): void
    {
        $this->actingAsOperator();
        $this->metaAcepta();

        $this->enviar(['caption' => 'Aqui esta su comprobante'])->assertStatus(201);

        Http::assertSent(function ($r) {
            return str_contains($r->url(), '/messages')
                && data_get($r->data(), 'image.caption') === 'Aqui esta su comprobante';
        });
    }

    /** Meta recibe el id de la subida, nunca el archivo dentro del mensaje. */
    public function test_el_mensaje_referencia_el_archivo_que_se_subio_antes(): void
    {
        $this->actingAsOperator();
        $this->metaAcepta();

        $this->enviar()->assertStatus(201);

        Http::assertSent(function ($r) {
            return str_contains($r->url(), '/messages')
                && data_get($r->data(), 'type') === 'image'
                && data_get($r->data(), 'image.id') === 'media-subido-1';
        });
    }

    /**
     * De punta a punta: se manda la foto y despues se puede abrir desde el chat.
     *
     * La ruta que entrega archivos nacio para lo que ENTRA; esto confirma que tambien sirve lo
     * que sale. Si no, el operador veria una burbuja rota justo despues de mandar algo.
     */
    public function test_la_foto_enviada_se_puede_abrir_desde_el_chat(): void
    {
        $this->actingAsOperator();
        $this->metaAcepta();

        $id = $this->enviar()->assertStatus(201)->json('data.id');

        $this->get("/api/conversations/media/{$id}")
             ->assertOk()
             ->assertHeader('X-Media-Disposition', 'inline');
    }

    // -- Cuando NO debe salir --------------------------------------------------

    /** Una foto a alguien de baja es igual de grave que un texto. */
    public function test_no_le_manda_una_imagen_a_quien_se_dio_de_baja(): void
    {
        $this->actingAsOperator();
        $this->metaAcepta();
        $this->contact->update(['status' => 'opted_out']);

        $this->enviar()
             ->assertStatus(422)
             ->assertJsonPath('code', 'OPTED_OUT');

        Http::assertNothingSent();
    }

    public function test_no_manda_nada_con_la_ventana_de_24h_cerrada(): void
    {
        $this->actingAsOperator();
        $this->metaAcepta();

        Conversation::query()->delete();

        $this->enviar()
             ->assertStatus(422)
             ->assertJsonPath('code', 'WINDOW_CLOSED');

        Http::assertNothingSent();
    }

    public function test_no_manda_nada_si_el_numero_esta_pausado(): void
    {
        $this->actingAsOperator();
        $this->metaAcepta();
        $this->phone->update(['paused_until' => now()->addHour()]);

        $this->enviar()
             ->assertStatus(422)
             ->assertJsonPath('code', 'PHONE_PAUSED');

        Http::assertNothingSent();
    }

    public function test_no_insiste_si_el_ultimo_envio_fallo_por_un_motivo_conocido(): void
    {
        $this->actingAsOperator();
        $this->metaAcepta();

        MessageLog::create([
            'phone_number_id'     => $this->phone->id,
            'to_number'           => $this->contact->phone,
            'template_name'       => 'hello_world',
            'language_code'       => 'en_US',
            'body_vars'           => [],
            'status'              => 'failed',
            'delivery_error_code' => 131026,
            'sent_at'             => now(),
        ]);

        $this->enviar()
             ->assertStatus(422)
             ->assertJsonPath('code', 'DELIVERY_BLOCKED');

        Http::assertNothingSent();
    }

    /** El agente solo atiende lo suyo, igual que para leer el chat. */
    public function test_un_agente_no_manda_imagenes_en_la_conversacion_de_otro(): void
    {
        $this->metaAcepta();

        $otro = User::factory()->create(['role' => 'agent', 'is_active' => true]);

        ConversationAssignment::create([
            'contact_id'     => $this->contact->id,
            'user_id'        => $otro->id,
            'assigned_by_id' => null,
            'action'         => ConversationAssignment::ACTION_AUTO,
            'assigned_at'    => now(),
        ]);

        $this->actingAsAgent();

        $this->enviar()->assertStatus(404);

        Http::assertNothingSent();
    }

    public function test_sin_sesion_no_se_puede_mandar_nada(): void
    {
        $this->metaAcepta();

        $this->postJson("/api/conversations/{$this->contact->id}/media")->assertStatus(401);

        Http::assertNothingSent();
    }

    /**
     * Pasarse de `post_max_size` no deja rastro que Laravel pueda mirar: PHP vacia `$_POST` y
     * `$_FILES` antes. Sin este caso, el operador recibia "el campo file es obligatorio"
     * despues de haber elegido un archivo, que es de las cosas que mas desorientan.
     */
    public function test_avisa_cuando_la_imagen_pasa_del_tope_del_servidor(): void
    {
        $this->actingAsOperator();
        $this->metaAcepta();

        // Peticion vacia que dice pesar mas de lo que PHP acepta: es exactamente como llega
        // una subida que se paso de `post_max_size`.
        $this->post("/api/conversations/{$this->contact->id}/media", [], [
            'Accept'         => 'application/json',
            'CONTENT_LENGTH' => '999999999',
        ])
             ->assertStatus(422)
             ->assertJsonPath('code', 'UPLOAD_LIMIT_EXCEEDED');

        Http::assertNothingSent();
    }

    // -- Lo que el operador puede mandar ---------------------------------------

    public function test_rechaza_lo_que_no_es_una_imagen(): void
    {
        $this->actingAsOperator();
        $this->metaAcepta();

        $this->post("/api/conversations/{$this->contact->id}/media", [
            'file' => UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_avisa_cuando_meta_rechaza_la_subida(): void
    {
        $this->actingAsOperator();

        Http::fake([
            '*/media' => Http::response(['error' => ['message' => 'Archivo no soportado']], 400),
        ]);

        $this->enviar()
             ->assertStatus(422)
             ->assertJsonPath('code', 'UPLOAD_REJECTED');

        // No quedo un mensaje a medias en el chat.
        $this->assertDatabaseMissing('conversations', ['direction' => 'outbound']);
    }

    public function test_si_el_envio_falla_no_deja_el_mensaje_en_el_chat(): void
    {
        $this->actingAsOperator();

        Http::fake([
            '*/media'    => Http::response(['id' => 'media-subido-1'], 200),
            '*/messages' => Http::response(['error' => ['message' => 'fallo']], 400),
        ]);

        $this->enviar()
             ->assertStatus(500)
             ->assertJsonPath('code', 'SEND_FAILED');

        $this->assertDatabaseMissing('conversations', ['direction' => 'outbound']);
    }
}
