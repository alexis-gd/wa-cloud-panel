<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Borrado de etiquetas con conteo previo y bloqueo por campaña sin enviar.
 *
 * Lo que se protege: `campaigns.tag_id` es `nullOnDelete`. Si se borra una etiqueta que usa
 * una campaña todavía ejecutable, esa campaña se queda sin segmento y al ejecutarla apunta a
 * TODA la base (CampaignController::execute solo segmenta `if ($campaign->tag_id)`).
 */
class TagDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    private function tagWithContacts(string $name, int $contacts = 0): Tag
    {
        $tag = Tag::create(['name' => $name]);

        for ($i = 0; $i < $contacts; $i++) {
            $contact = Contact::create([
                'phone'  => '5299' . str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'name'   => "Contacto {$i}",
                'status' => 'active',
                'source' => 'manual',
            ]);
            $contact->tags()->attach($tag->id);
        }

        return $tag;
    }

    private function campaign(Tag $tag, string $status): Campaign
    {
        return Campaign::create([
            'name'          => "Campaña {$status}",
            'channel'       => 'sms',
            'sms_body'      => 'Hola',
            'status'        => $status,
            'tag_id'        => $tag->id,
            'sent_count'    => 0,
            'failed_count'  => 0,
        ]);
    }

    // ── Conteo previo ────────────────────────────────────────────────────────

    public function test_usage_devuelve_el_conteo_de_contactos_y_campanas(): void
    {
        $tag = $this->tagWithContacts('VIP', 3);
        $this->campaign($tag, 'completed');

        $res = $this->getJson("/api/tags/{$tag->id}/usage")->assertOk();

        $this->assertSame(3, $res->json('data.contacts'));
        $this->assertSame(1, $res->json('data.campaigns'));
        $this->assertFalse($res->json('data.blocked'));
        $this->assertNull($res->json('data.reason'));
    }

    public function test_usage_marca_bloqueo_y_nombra_las_campanas_sin_enviar(): void
    {
        $tag = $this->tagWithContacts('VIP', 2);
        $this->campaign($tag, 'draft');

        $res = $this->getJson("/api/tags/{$tag->id}/usage")->assertOk();

        $this->assertTrue($res->json('data.blocked'));
        $this->assertCount(1, $res->json('data.blocking'));
        $this->assertSame('Campaña draft', $res->json('data.blocking.0.name'));
        $this->assertStringContainsString('Campaña draft', $res->json('data.reason'));
    }

    public function test_usage_devuelve_404_si_la_etiqueta_no_existe(): void
    {
        $this->getJson('/api/tags/999/usage')->assertStatus(404);
    }

    public function test_usage_requiere_auth(): void
    {
        $tag = $this->tagWithContacts('VIP');

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/tags/{$tag->id}/usage")->assertStatus(401);
    }

    // ── Bloqueo del borrado ──────────────────────────────────────────────────

    public function test_no_borra_si_una_campana_en_borrador_usa_la_etiqueta(): void
    {
        $tag = $this->tagWithContacts('VIP', 2);
        $this->campaign($tag, 'draft');

        $res = $this->deleteJson("/api/tags/{$tag->id}")->assertStatus(422);

        $this->assertSame('TAG_IN_USE_BY_CAMPAIGN', $res->json('code'));
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    public function test_no_borra_si_una_campana_pausada_usa_la_etiqueta(): void
    {
        // `paused` tambien puede ejecutarse (CampaignController::execute), asi que bloquea igual.
        $tag = $this->tagWithContacts('VIP');
        $this->campaign($tag, 'paused');

        $this->deleteJson("/api/tags/{$tag->id}")->assertStatus(422);
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    public function test_si_borra_cuando_las_campanas_ya_corrieron(): void
    {
        // Una campaña completada solo pierde la referencia del segmento; su historial de
        // envíos no cambia y no se puede volver a ejecutar.
        $tag = $this->tagWithContacts('VIP', 4);
        $campaign = $this->campaign($tag, 'completed');

        $res = $this->deleteJson("/api/tags/{$tag->id}")->assertOk();

        $this->assertSame(4, $res->json('data.contacts_untagged'));
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertNull($campaign->fresh()->tag_id);
    }

    public function test_si_borra_una_etiqueta_sin_campanas(): void
    {
        $tag = $this->tagWithContacts('Sin uso', 2);

        $this->deleteJson("/api/tags/{$tag->id}")->assertOk();

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
        $this->assertDatabaseCount('contact_tag', 0);
    }

    public function test_una_campana_cancelada_no_bloquea(): void
    {
        $tag = $this->tagWithContacts('VIP');
        $this->campaign($tag, 'cancelled');

        $this->deleteJson("/api/tags/{$tag->id}")->assertOk();
    }

    public function test_una_campana_de_otra_etiqueta_no_bloquea(): void
    {
        $otra   = $this->tagWithContacts('Otra');
        $borrar = Tag::create(['name' => 'Borrar']);
        $this->campaign($otra, 'draft');

        $this->deleteJson("/api/tags/{$borrar->id}")->assertOk();
    }

    public function test_el_borrado_revalida_el_bloqueo_no_confia_en_el_conteo_previo(): void
    {
        // El operador ve el conteo (sin bloqueo) y confirma minutos despues; mientras tanto
        // alguien creo una campaña con esa etiqueta. El DELETE tiene que atajarlo igual.
        $tag = $this->tagWithContacts('VIP');

        $this->getJson("/api/tags/{$tag->id}/usage")->assertOk()->assertJsonPath('data.blocked', false);

        $this->campaign($tag, 'draft');

        $this->deleteJson("/api/tags/{$tag->id}")->assertStatus(422);
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
    }

    public function test_borrar_una_etiqueta_no_borra_los_contactos(): void
    {
        $tag = $this->tagWithContacts('VIP', 3);

        $this->deleteJson("/api/tags/{$tag->id}")->assertOk();

        $this->assertSame(3, Contact::count());
    }
}
