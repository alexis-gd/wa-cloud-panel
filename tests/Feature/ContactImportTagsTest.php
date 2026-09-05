<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Columna de etiqueta en el Excel/CSV de contactos.
 *
 * El importador dejó de ser sobre todo un alta (las altas vienen del API externa): ahora su
 * caso de uso principal es ETIQUETAR contactos que ya existen. Por eso una fila cuyo teléfono
 * ya está en la base recibe su etiqueta igual, en vez de descartarse como duplicado.
 */
class ContactImportTagsTest extends TestCase
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

    private function tagsOf(string $phone): array
    {
        return Contact::where('phone', $phone)->firstOrFail()
            ->tags()->pluck('name')->sort()->values()->all();
    }

    // ── Alta con etiqueta ────────────────────────────────────────────────────

    public function test_crea_la_etiqueta_y_la_asigna_al_contacto_nuevo(): void
    {
        $res = $this->import($this->csv("telefono,nombre,etiqueta\n6692522844,Joseph,VIP\n"))
                    ->assertStatus(200);

        $this->assertSame(1, $res->json('summary.inserted'));
        $this->assertSame(1, $res->json('summary.tags_created'));
        $this->assertSame(1, $res->json('summary.tags_assigned'));
        $this->assertSame(['VIP'], $this->tagsOf('526692522844'));
    }

    public function test_reusa_una_etiqueta_que_ya_existe(): void
    {
        Tag::create(['name' => 'VIP']);

        $res = $this->import($this->csv("telefono,etiqueta\n6692522844,VIP\n"))->assertStatus(200);

        $this->assertSame(0, $res->json('summary.tags_created'));
        $this->assertSame(1, Tag::count());
    }

    public function test_la_etiqueta_no_distingue_mayusculas(): void
    {
        // "VIP", "vip" y "Vip" son la misma etiqueta: la llave es el slug, no el nombre.
        // Si se comparara por nombre, el catálogo se llenaría de duplicados indistinguibles.
        Tag::create(['name' => 'VIP']);

        $res = $this->import($this->csv("telefono,etiqueta\n6692522844,vip\n6692522845,Vip\n"))
                    ->assertStatus(200);

        $this->assertSame(0, $res->json('summary.tags_created'));
        $this->assertSame(1, Tag::count());
    }

    public function test_varias_etiquetas_en_la_misma_celda(): void
    {
        $res = $this->import($this->csv("telefono,etiqueta\n6692522844,\"VIP, Mazatlan; Referido\"\n"))
                    ->assertStatus(200);

        $this->assertSame(3, $res->json('summary.tags_created'));
        $this->assertSame(['Mazatlan', 'Referido', 'VIP'], $this->tagsOf('526692522844'));
    }

    public function test_reconoce_los_encabezados_de_etiqueta(): void
    {
        foreach (['etiqueta', 'etiquetas', 'tag', 'tags'] as $header) {
            Contact::query()->forceDelete();
            Tag::query()->delete();

            $this->import($this->csv("telefono,{$header}\n6692522844,VIP\n"))->assertStatus(200);

            $this->assertSame(['VIP'], $this->tagsOf('526692522844'), "Falló con encabezado '{$header}'");
        }
    }

    public function test_la_columna_de_etiqueta_puede_ir_en_cualquier_orden(): void
    {
        $this->import($this->csv("etiqueta,telefono,nombre\nVIP,6692522844,Joseph\n"))->assertStatus(200);

        $this->assertSame(['VIP'], $this->tagsOf('526692522844'));
        $this->assertDatabaseHas('contacts', ['phone' => '526692522844', 'name' => 'Joseph']);
    }

    // ── El caso principal: etiquetar contactos que YA existen ────────────────

    public function test_etiqueta_a_un_contacto_que_ya_existe(): void
    {
        Contact::factory()->create(['phone' => '526692522844']);

        $res = $this->import($this->csv("telefono,etiqueta\n6692522844,VIP\n"))->assertStatus(200);

        $this->assertSame(0, $res->json('summary.inserted'));
        $this->assertSame(1, $res->json('summary.duplicates'));
        $this->assertSame(1, $res->json('summary.duplicates_tagged'));
        $this->assertSame(['VIP'], $this->tagsOf('526692522844'));
    }

    public function test_no_pisa_las_etiquetas_que_el_contacto_ya_tenia(): void
    {
        $contact = Contact::factory()->create(['phone' => '526692522844']);
        $contact->tags()->attach(Tag::create(['name' => 'Antigua'])->id);

        $this->import($this->csv("telefono,etiqueta\n6692522844,VIP\n"))->assertStatus(200);

        $this->assertSame(['Antigua', 'VIP'], $this->tagsOf('526692522844'));
    }

    public function test_reimportar_la_misma_etiqueta_no_duplica_la_relacion(): void
    {
        $csv = "telefono,etiqueta\n6692522844,VIP\n";

        $this->import($this->csv($csv))->assertStatus(200);
        $res = $this->import($this->csv($csv))->assertStatus(200);

        $this->assertSame(0, $res->json('summary.tags_assigned'));
        $this->assertSame(1, Contact::where('phone', '526692522844')->firstOrFail()->tags()->count());
        $this->assertDatabaseCount('contact_tag', 1);
    }

    public function test_un_contacto_borrado_no_se_reetiqueta(): void
    {
        // Un borrado sigue ocupando el número (UNIQUE), pero está fuera de listas y campañas:
        // etiquetarlo lo devolvería a un segmento sin que nadie lo reactive.
        $contact = Contact::factory()->create(['phone' => '526692522844']);
        $contact->delete();

        $res = $this->import($this->csv("telefono,etiqueta\n6692522844,VIP\n"))->assertStatus(200);

        $this->assertSame(0, $res->json('summary.inserted'));
        $this->assertSame(1, $res->json('summary.duplicates'));
        $this->assertDatabaseCount('contact_tag', 0);
    }

    // ── Casos borde ──────────────────────────────────────────────────────────

    public function test_una_celda_de_etiqueta_vacia_no_crea_nada(): void
    {
        $res = $this->import($this->csv("telefono,etiqueta\n6692522844,\n6692522845,\"  \"\n"))
                    ->assertStatus(200);

        $this->assertSame(0, $res->json('summary.tags_created'));
        $this->assertSame(0, Tag::count());
    }

    public function test_una_celda_con_puros_signos_no_crea_etiqueta(): void
    {
        // "---" no deja slug: no es un nombre de etiqueta, es basura de la hoja.
        $res = $this->import($this->csv("telefono,etiqueta\n6692522844,---\n"))->assertStatus(200);

        $this->assertSame(0, $res->json('summary.tags_created'));
        $this->assertSame(0, Tag::count());
    }

    public function test_el_mismo_numero_dos_veces_acumula_sus_etiquetas(): void
    {
        $res = $this->import($this->csv("telefono,etiqueta\n6692522844,VIP\n6692522844,Referido\n"))
                    ->assertStatus(200);

        $this->assertSame(1, $res->json('summary.inserted'));
        $this->assertSame(['Referido', 'VIP'], $this->tagsOf('526692522844'));
    }

    public function test_una_fila_con_telefono_invalido_no_arrastra_su_etiqueta(): void
    {
        $res = $this->import($this->csv("telefono,etiqueta\n123,VIP\n6692522844,Bueno\n"))
                    ->assertStatus(200);

        $this->assertSame(1, $res->json('summary.invalid'));
        $this->assertSame(['Bueno'], $this->tagsOf('526692522844'));
        $this->assertDatabaseCount('contact_tag', 1);
    }

    public function test_sin_columna_de_etiqueta_todo_sigue_igual(): void
    {
        $res = $this->import($this->csv("telefono,nombre\n6692522844,Joseph\n"))->assertStatus(200);

        $this->assertFalse($res->json('summary.has_tag_column'));
        $this->assertSame(0, $res->json('summary.tags_assigned'));
        $this->assertDatabaseCount('contact_tag', 0);
    }

    public function test_importacion_grande_con_etiquetas(): void
    {
        // 300 filas, 3 etiquetas. Lo que se protege es que no haya N+1: todo va por lotes.
        $lineas = ["telefono,etiqueta"];
        for ($i = 0; $i < 300; $i++) {
            $tag = 'Grupo ' . ($i % 3);
            $lineas[] = '5299' . str_pad((string) $i, 8, '0', STR_PAD_LEFT) . ",{$tag}";
        }

        $res = $this->import($this->csv(implode("\n", $lineas) . "\n"))->assertStatus(200);

        $this->assertSame(300, $res->json('summary.inserted'));
        $this->assertSame(3, $res->json('summary.tags_created'));
        $this->assertSame(300, $res->json('summary.tags_assigned'));
        $this->assertDatabaseCount('contact_tag', 300);
    }
}
