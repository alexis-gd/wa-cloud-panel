<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catálogo de etiquetas: contadores, búsqueda y renombrado.
 *
 * Lo que se protege del renombrado: el SLUG no cambia. Es la llave con la que el importador
 * reconoce una etiqueta existente (TagResolver). Si el slug se regenerara al renombrar, el
 * mismo Excel dejaría de reconocerla y crearía una etiqueta duplicada.
 */
class TagCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    private function contact(string $phone): Contact
    {
        return Contact::create([
            'phone'  => $phone,
            'name'   => 'Prueba',
            'status' => 'active',
            'source' => 'manual',
        ]);
    }

    private function campaign(Tag $tag, string $status = 'completed'): Campaign
    {
        return Campaign::create([
            'name'         => 'Campaña',
            'channel'      => 'sms',
            'sms_body'     => 'Hola',
            'status'       => $status,
            'tag_id'       => $tag->id,
            'sent_count'   => 0,
            'failed_count' => 0,
        ]);
    }

    // ── Contadores ───────────────────────────────────────────────────────────

    public function test_el_catalogo_trae_contactos_y_campanas_por_etiqueta(): void
    {
        $vip = Tag::create(['name' => 'VIP']);
        $this->contact('529231111111')->tags()->attach($vip->id);
        $this->contact('529232222222')->tags()->attach($vip->id);
        $this->campaign($vip);

        Tag::create(['name' => 'Sin uso']);

        $data = collect($this->getJson('/api/tags')->assertOk()->json('data'))->keyBy('name');

        $this->assertSame(2, $data['VIP']['contacts_count']);
        $this->assertSame(1, $data['VIP']['campaigns_count']);
        $this->assertSame(0, $data['Sin uso']['contacts_count']);
        $this->assertSame(0, $data['Sin uso']['campaigns_count']);
    }

    public function test_el_catalogo_ordena_por_nombre(): void
    {
        Tag::create(['name' => 'Zacatecas']);
        Tag::create(['name' => 'Mazatlan']);
        Tag::create(['name' => 'Culiacan']);

        $nombres = collect($this->getJson('/api/tags')->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Culiacan', 'Mazatlan', 'Zacatecas'], $nombres);
    }

    public function test_el_catalogo_filtra_por_texto(): void
    {
        Tag::create(['name' => 'Mazatlan Centro']);
        Tag::create(['name' => 'Mazatlan Norte']);
        Tag::create(['name' => 'Culiacan']);

        $data = $this->getJson('/api/tags?q=mazatlan')->assertOk()->json('data');

        $this->assertCount(2, $data);
    }

    public function test_el_catalogo_devuelve_la_lista_completa_no_paginada(): void
    {
        // Los selectores de Contactos y Campañas necesitan TODAS las etiquetas; si el
        // endpoint paginara, esos selectores quedarían incompletos sin que nadie lo note.
        for ($i = 0; $i < 60; $i++) {
            Tag::create(['name' => "Etiqueta {$i}"]);
        }

        $this->assertCount(60, $this->getJson('/api/tags')->assertOk()->json('data'));
    }

    // ── Renombrar ────────────────────────────────────────────────────────────

    public function test_renombrar_cambia_el_nombre(): void
    {
        $tag = Tag::create(['name' => 'VIP']);

        $this->putJson("/api/tags/{$tag->id}", ['name' => 'Preferentes'])
             ->assertOk()
             ->assertJsonPath('data.name', 'Preferentes');

        $this->assertSame('Preferentes', $tag->fresh()->name);
    }

    public function test_renombrar_NO_cambia_el_slug(): void
    {
        $tag = Tag::create(['name' => 'VIP']);
        $slugOriginal = $tag->slug;

        $this->putJson("/api/tags/{$tag->id}", ['name' => 'Preferentes'])->assertOk();

        $this->assertSame($slugOriginal, $tag->fresh()->slug);
    }

    public function test_renombrar_no_rompe_el_reconocimiento_del_importador(): void
    {
        // El Excel trae el nombre viejo. Como la llave es el slug y el slug no cambió,
        // el importador sigue apuntando a la MISMA etiqueta y no crea una duplicada.
        $tag = Tag::create(['name' => 'VIP']);
        $this->putJson("/api/tags/{$tag->id}", ['name' => 'Preferentes'])->assertOk();

        $resuelto = \App\Services\Tags\TagResolver::resolve(['VIP']);

        $this->assertSame(0, $resuelto['created']);
        $this->assertSame($tag->id, $resuelto['ids'][$tag->slug]);
        $this->assertSame(1, Tag::count());
    }

    public function test_renombrar_conserva_los_contactos_asignados(): void
    {
        $tag = Tag::create(['name' => 'VIP']);
        $this->contact('529231111111')->tags()->attach($tag->id);

        $this->putJson("/api/tags/{$tag->id}", ['name' => 'Preferentes'])->assertOk();

        $this->assertSame(1, $tag->fresh()->contacts()->count());
    }

    public function test_renombrar_rechaza_un_nombre_ya_usado(): void
    {
        Tag::create(['name' => 'VIP']);
        $otra = Tag::create(['name' => 'Referido']);

        $this->putJson("/api/tags/{$otra->id}", ['name' => 'VIP'])->assertStatus(422);
        $this->assertSame('Referido', $otra->fresh()->name);
    }

    public function test_renombrar_con_el_mismo_nombre_no_choca_consigo_misma(): void
    {
        $tag = Tag::create(['name' => 'VIP']);

        $this->putJson("/api/tags/{$tag->id}", ['name' => 'VIP'])->assertOk();
    }

    public function test_renombrar_exige_nombre(): void
    {
        $tag = Tag::create(['name' => 'VIP']);

        $this->putJson("/api/tags/{$tag->id}", ['name' => ''])->assertStatus(422);
    }

    public function test_renombrar_devuelve_404_si_no_existe(): void
    {
        $this->putJson('/api/tags/999', ['name' => 'X'])->assertStatus(404);
    }

    public function test_renombrar_requiere_auth(): void
    {
        $tag = Tag::create(['name' => 'VIP']);

        $this->app['auth']->forgetGuards();
        $this->putJson("/api/tags/{$tag->id}", ['name' => 'X'])->assertStatus(401);
    }

    public function test_un_operador_si_puede_renombrar(): void
    {
        // El operador ya crea y borra etiquetas desde Contactos (rutas role:admin,operator).
        // Renombrar sigue la misma regla: quitarselo seria incoherente con lo que ya hace.
        $tag = Tag::create(['name' => 'VIP']);

        $this->actingAsOperator()
             ->putJson("/api/tags/{$tag->id}", ['name' => 'Preferentes'])
             ->assertOk();
    }

    public function test_un_agente_no_toca_el_catalogo(): void
    {
        // El agente vive encerrado en Conversaciones.
        $tag = Tag::create(['name' => 'VIP']);

        $this->actingAsAgent()->getJson('/api/tags')->assertStatus(403);
        $this->actingAsAgent()->putJson("/api/tags/{$tag->id}", ['name' => 'X'])->assertStatus(403);
    }

    // -- Nombres distintos que chocan en el identificador ---------------------

    public function test_rechaza_un_nombre_que_choca_en_el_identificador(): void
    {
        // Bug encontrado en QA: "QA Import Renombrada" y "qa import" tienen nombres
        // distintos, asi que `unique:tags,name` los dejaba pasar, y el choque estallaba
        // en MySQL con un "Integrity constraint violation 1062" en crudo, en pantalla.
        Tag::create(['name' => 'QA Import Renombrada', 'slug' => 'qa-import']);

        $res = $this->postJson('/api/tags', ['name' => 'qa import']);

        $res->assertStatus(422);
        $this->assertSame('DUPLICATE_SLUG', $res->json('code'));
        $this->assertStringContainsString('QA Import Renombrada', $res->json('message'));
        $this->assertSame(1, Tag::count());
    }

    public function test_el_choque_de_identificador_tambien_aplica_con_acentos_y_signos(): void
    {
        // "Mazatlan" y "¡Mazatlan!" acaban en el mismo identificador.
        Tag::create(['name' => 'Mazatlan']);

        $this->postJson('/api/tags', ['name' => '¡Mazatlan!'])
             ->assertStatus(422)
             ->assertJsonPath('code', 'DUPLICATE_SLUG');
    }

    public function test_un_nombre_libre_si_se_crea(): void
    {
        Tag::create(['name' => 'QA Import Renombrada', 'slug' => 'qa-import']);

        $this->postJson('/api/tags', ['name' => 'Otra Etiqueta'])
             ->assertStatus(201);

        $this->assertSame(2, Tag::count());
    }

    public function test_el_nombre_repetido_avisa_en_espanol(): void
    {
        // El operador nunca debe leer el mensaje por default de Laravel, que va en ingles.
        Tag::create(['name' => 'VIP']);

        $res = $this->postJson('/api/tags', ['name' => 'VIP']);

        $res->assertStatus(422);
        $this->assertStringContainsString('Ya existe una etiqueta con ese nombre', $res->json('message'));
    }
}
