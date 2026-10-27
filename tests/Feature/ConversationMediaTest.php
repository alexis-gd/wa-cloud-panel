<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Services\WhatsApp\MediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ver en el panel los archivos que manda el contacto.
 *
 * Los archivos viven en el disco privado, fuera de `public/`: la unica forma de abrirlos es
 * esta ruta, y por eso aqui se prueban las tres cosas que la ruta tiene que cuidar antes de
 * soltar un byte - que haya sesion, que al agente solo le toque lo suyo, y que un archivo que
 * mando un desconocido no se abra como pagina en nuestro dominio.
 */
class ConversationMediaTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(MediaStore::DISK);
        PhoneNumber::factory()->create(['is_active' => true]);

        $this->contact = Contact::factory()->create(['phone' => '529231311146']);
    }

    /** Un mensaje con su archivo ya en disco. */
    private function conArchivo(array $attrs = [], ?Contact $contact = null): Conversation
    {
        $ruta = 'media/2026/10/1/' . ($attrs['_nombre'] ?? 'archivo') . '.jpg';
        unset($attrs['_nombre']);

        Storage::disk(MediaStore::DISK)->put($ruta, 'contenido-binario');

        return Conversation::create(array_merge([
            'contact_id'   => ($contact ?? $this->contact)->id,
            'direction'    => 'inbound',
            'message_type' => 'image',
            'body'         => '',
            'status'       => 'received',
            'window_open'  => true,
            'media_id'     => 'media-abc-123',
            'media_path'   => $ruta,
            'media_mime'   => 'image/jpeg',
            'media_size'   => 17,
            'media_status' => 'stored',
        ], $attrs));
    }

    // -- Hace falta sesion -----------------------------------------------------

    public function test_sin_sesion_no_entrega_el_archivo(): void
    {
        $conversation = $this->conArchivo();

        $this->getJson("/api/conversations/media/{$conversation->id}")->assertStatus(401);
    }

    public function test_con_sesion_entrega_el_archivo(): void
    {
        $this->actingAsAdmin();

        $conversation = $this->conArchivo();

        $res = $this->get("/api/conversations/media/{$conversation->id}");

        $res->assertOk();
        $res->assertHeader('Content-Type', 'image/jpeg');
        // `response()->file()` devuelve un BinaryFileResponse: el contenido es el archivo en
        // disco, no un cuerpo armado en memoria.
        $this->assertSame(
            'contenido-binario',
            file_get_contents($res->baseResponse->getFile()->getPathname()),
        );
    }

    // -- Al agente solo lo suyo ------------------------------------------------

    private function agente(): User
    {
        $agente = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        Sanctum::actingAs($agente);

        return $agente;
    }

    private function asignarA(Contact $contact, User $user): void
    {
        ConversationAssignment::create([
            'contact_id'     => $contact->id,
            'user_id'        => $user->id,
            'assigned_by_id' => null,
            'action'         => ConversationAssignment::ACTION_AUTO,
            'assigned_at'    => now(),
        ]);
    }

    public function test_el_agente_abre_el_archivo_de_su_conversacion(): void
    {
        $agente = $this->agente();
        $this->asignarA($this->contact, $agente);

        $conversation = $this->conArchivo();

        $this->get("/api/conversations/media/{$conversation->id}")->assertOk();
    }

    /**
     * 404 y no 403: un 403 le confirmaria al agente que ese mensaje existe, que es justo lo
     * que no tiene que poder averiguar de una conversacion que no es suya.
     */
    public function test_el_agente_no_abre_el_archivo_de_otro_agente(): void
    {
        $otro = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $this->asignarA($this->contact, $otro);

        $conversation = $this->conArchivo();

        $this->agente();

        $this->getJson("/api/conversations/media/{$conversation->id}")->assertStatus(404);
    }

    public function test_el_agente_no_abre_el_archivo_de_una_conversacion_sin_asignar(): void
    {
        $conversation = $this->conArchivo();

        $this->agente();

        $this->getJson("/api/conversations/media/{$conversation->id}")->assertStatus(404);
    }

    /** El operador reparte el trabajo: tiene que poder ver todo. */
    public function test_el_operador_abre_cualquier_archivo(): void
    {
        $this->actingAsOperator();

        $conversation = $this->conArchivo();

        $this->get("/api/conversations/media/{$conversation->id}")->assertOk();
    }

    // -- El archivo no se ejecuta en nuestro dominio ---------------------------

    public function test_una_foto_se_muestra_en_linea(): void
    {
        $this->actingAsAdmin();

        $conversation = $this->conArchivo();

        $this->get("/api/conversations/media/{$conversation->id}")
             ->assertOk()
             ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringStartsWith(
            'inline',
            $this->get("/api/conversations/media/{$conversation->id}")->headers->get('Content-Disposition'),
        );
    }

    /**
     * El caso peligroso: un contacto manda un SVG (o un HTML) como documento. Si se abriera en
     * linea, su JavaScript correria en nuestro dominio, donde vive el token del operador.
     */
    public function test_un_svg_se_descarga_en_vez_de_abrirse(): void
    {
        $this->actingAsAdmin();

        $conversation = $this->conArchivo([
            'message_type'   => 'document',
            'media_mime'     => 'image/svg+xml',
            'media_filename' => 'comprobante.svg',
        ]);

        $disposicion = $this->get("/api/conversations/media/{$conversation->id}")
            ->assertOk()
            ->headers->get('Content-Disposition');

        $this->assertStringStartsWith('attachment', $disposicion);
    }

    public function test_un_pdf_se_muestra_en_linea_para_poder_revisarlo(): void
    {
        $this->actingAsAdmin();

        $conversation = $this->conArchivo([
            'message_type' => 'document',
            'media_mime'   => 'application/pdf',
        ]);

        $disposicion = $this->get("/api/conversations/media/{$conversation->id}")
            ->headers->get('Content-Disposition');

        $this->assertStringStartsWith('inline', $disposicion);
    }

    /** El nombre lo escribio el contacto: un salto de linea ahi inyecta encabezados. */
    public function test_el_nombre_del_archivo_se_limpia_antes_de_ir_en_el_encabezado(): void
    {
        $this->actingAsAdmin();

        $conversation = $this->conArchivo([
            'message_type'   => 'document',
            'media_mime'     => 'application/pdf',
            'media_filename' => "ine\r\nX-Inyectado: si.pdf",
        ]);

        $disposicion = $this->get("/api/conversations/media/{$conversation->id}")
            ->headers->get('Content-Disposition');

        $this->assertStringNotContainsString("\r", $disposicion);
        $this->assertStringNotContainsString("\n", $disposicion);
    }

    // -- Cuando no hay nada que entregar ---------------------------------------

    public function test_un_archivo_que_sigue_en_la_cola_avisa_que_espere(): void
    {
        $this->actingAsAdmin();

        $conversation = $this->conArchivo(['media_status' => 'pending', 'media_path' => null]);

        $this->getJson("/api/conversations/media/{$conversation->id}")
             ->assertStatus(422)
             ->assertJsonPath('code', 'MEDIA_NOT_READY');
    }

    public function test_un_archivo_que_no_se_pudo_bajar_avisa_que_lo_reenvien(): void
    {
        $this->actingAsAdmin();

        $conversation = $this->conArchivo(['media_status' => 'failed']);

        $this->getJson("/api/conversations/media/{$conversation->id}")
             ->assertStatus(422)
             ->assertJsonPath('code', 'MEDIA_UNAVAILABLE');
    }

    /** Borrado por retencion, o disco limpiado: la fila dice `stored` pero el archivo no esta. */
    public function test_si_el_archivo_ya_no_esta_en_disco_no_revienta(): void
    {
        $this->actingAsAdmin();

        $conversation = $this->conArchivo();
        Storage::disk(MediaStore::DISK)->delete($conversation->media_path);

        $this->getJson("/api/conversations/media/{$conversation->id}")
             ->assertStatus(422)
             ->assertJsonPath('code', 'MEDIA_UNAVAILABLE');
    }

    public function test_un_mensaje_de_texto_no_tiene_archivo_que_entregar(): void
    {
        $this->actingAsAdmin();

        $conversation = Conversation::create([
            'contact_id'   => $this->contact->id,
            'direction'    => 'inbound',
            'message_type' => 'text',
            'body'         => 'Hola',
            'status'       => 'received',
            'window_open'  => true,
        ]);

        $this->getJson("/api/conversations/media/{$conversation->id}")->assertStatus(404);
    }

    public function test_un_mensaje_que_no_existe_da_404(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/conversations/media/999999')->assertStatus(404);
    }

    // -- Lo que el panel necesita saber de cada mensaje -------------------------

    public function test_el_detalle_del_chat_trae_el_estado_del_archivo(): void
    {
        $this->actingAsAdmin();

        $this->conArchivo([
            'message_type'   => 'document',
            'media_mime'     => 'application/pdf',
            'media_filename' => 'comprobante.pdf',
        ]);

        $mensaje = $this->getJson("/api/conversations/{$this->contact->id}")
            ->assertOk()
            ->json('data.messages.0');

        $this->assertSame('document',        $mensaje['message_type']);
        $this->assertSame('stored',          $mensaje['media_status']);
        $this->assertSame('application/pdf', $mensaje['media_mime']);
        $this->assertSame('comprobante.pdf', $mensaje['media_filename']);
    }

    /** Sin esto la fila de la lista salia en blanco: una foto sin pie de foto no tiene cuerpo. */
    public function test_la_lista_dice_que_hay_una_foto_cuando_no_trae_pie_de_foto(): void
    {
        $this->actingAsAdmin();

        $this->conArchivo();

        $this->getJson('/api/conversations')
             ->assertOk()
             ->assertJsonPath('data.0.last_message', 'Foto');
    }

    public function test_la_lista_prefiere_el_pie_de_foto_cuando_lo_hay(): void
    {
        $this->actingAsAdmin();

        $this->conArchivo(['body' => 'Aqui esta mi INE']);

        $this->getJson('/api/conversations')
             ->assertJsonPath('data.0.last_message', 'Aqui esta mi INE');
    }

    // -- El hueco que esto cerro de paso ---------------------------------------

    /**
     * La lista si le escondia al agente lo que no es suyo, pero `GET /conversations/{id}` no:
     * pidiendo el id a mano leia la conversacion de cualquiera, con todo su historial.
     */
    public function test_el_agente_no_abre_el_chat_de_otro_agente(): void
    {
        $otro = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $this->asignarA($this->contact, $otro);

        $this->conArchivo();

        $this->agente();

        $this->getJson("/api/conversations/{$this->contact->id}")->assertStatus(404);
    }

    public function test_el_agente_si_abre_el_chat_que_tiene_asignado(): void
    {
        $agente = $this->agente();
        $this->asignarA($this->contact, $agente);

        $this->conArchivo();

        $this->getJson("/api/conversations/{$this->contact->id}")->assertOk();
    }

    /** Lo que ya funcionaba no se rompe: admin y operador siguen viendo todas. */
    public function test_el_admin_abre_cualquier_chat(): void
    {
        $otro = User::factory()->create(['role' => 'agent', 'is_active' => true]);
        $this->asignarA($this->contact, $otro);

        $this->actingAsAdmin();

        $this->getJson("/api/conversations/{$this->contact->id}")->assertOk();
    }
}
