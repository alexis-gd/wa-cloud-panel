<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Estado de cartera del cliente: filtro en Contactos y etiquetado masivo por filtro.
 *
 * El flujo que esto habilita: el operador filtra por "LIQUIDADO", etiqueta a todos con
 * "Renovacion Septiembre" y crea la campana con esa etiqueta. La columna dice como esta
 * hoy la persona; la etiqueta registra a quien se decidio mandarle.
 */
class ContactPortfolioStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->create(['role' => 'operator']);
    }

    private function contacto(string $phone, ?string $portfolio, string $status = 'active'): Contact
    {
        return Contact::create([
            'phone'            => $phone,
            'name'             => 'Contacto ' . $phone,
            'status'           => $status,
            'source'           => 'api',
            'portfolio_status' => $portfolio,
        ]);
    }

    // ── Filtro ───────────────────────────────────────────────────────────────

    public function test_filtra_los_contactos_por_estado_de_cartera(): void
    {
        $this->contacto('529000000001', 'LIQUIDADO');
        $this->contacto('529000000002', 'LIQUIDADO');
        $this->contacto('529000000003', 'BURO');
        $this->contacto('529000000004', null);

        $res = $this->actingAs($this->operator)
                    ->getJson('/api/contacts?portfolio_status=LIQUIDADO');

        $res->assertOk();
        $this->assertSame(2, $res->json('total'));
    }

    public function test_sin_filtro_de_cartera_salen_todos(): void
    {
        $this->contacto('529000000001', 'LIQUIDADO');
        $this->contacto('529000000002', null);

        $res = $this->actingAs($this->operator)->getJson('/api/contacts');

        $this->assertSame(2, $res->json('total'));
    }

    public function test_el_filtro_de_cartera_se_suma_a_los_demas_filtros(): void
    {
        // Un LIQUIDADO dado de baja no debe salir al pedir "activos + LIQUIDADO".
        $this->contacto('529000000001', 'LIQUIDADO');
        $this->contacto('529000000002', 'LIQUIDADO', 'opted_out');

        $res = $this->actingAs($this->operator)
                    ->getJson('/api/contacts?portfolio_status=LIQUIDADO&status=active');

        $this->assertSame(1, $res->json('total'));
        $this->assertSame('529000000001', $res->json('data.0.phone'));
    }

    public function test_el_desplegable_lista_los_estados_que_existen_en_la_base(): void
    {
        // Sale de los datos, no de una lista fija: si su API manda un estado nuevo, aparece.
        $this->contacto('529000000001', 'LIQUIDADO');
        $this->contacto('529000000002', 'BURO');
        $this->contacto('529000000003', 'LIQUIDADO');
        $this->contacto('529000000004', null);

        $res = $this->actingAs($this->operator)
                    ->getJson('/api/contacts/portfolio-statuses');

        $res->assertOk();
        $this->assertSame(['BURO', 'LIQUIDADO'], $res->json('data'));
    }

    public function test_el_desplegable_no_trae_vacios(): void
    {
        $this->contacto('529000000001', 'LIQUIDADO');
        $this->contacto('529000000002', '');

        $res = $this->actingAs($this->operator)
                    ->getJson('/api/contacts/portfolio-statuses');

        $this->assertSame(['LIQUIDADO'], $res->json('data'));
    }

    public function test_un_agente_no_puede_ver_los_estados_de_cartera(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);

        $this->actingAs($agent)
             ->getJson('/api/contacts/portfolio-statuses')
             ->assertForbidden();
    }

    // ── Etiquetado masivo sobre el filtro ────────────────────────────────────

    public function test_el_preview_cuenta_lo_que_se_va_a_etiquetar(): void
    {
        // El operador tiene que ver el numero antes de confirmar: etiquetar 40,000
        // contactos de un clic no se deshace facil.
        $this->contacto('529000000001', 'LIQUIDADO');
        $this->contacto('529000000002', 'LIQUIDADO');
        $this->contacto('529000000003', 'BURO');

        $res = $this->actingAs($this->operator)
                    ->postJson('/api/contacts/tags/bulk-preview', ['portfolio_status' => 'LIQUIDADO']);

        $res->assertOk();
        $this->assertSame(2, $res->json('data.total'));
    }

    public function test_etiqueta_todo_lo_filtrado_no_solo_lo_visible(): void
    {
        // El punto del endpoint: con casillas solo alcanzas lo cargado en pantalla.
        for ($i = 1; $i <= 120; $i++) {
            $this->contacto('5290000' . str_pad((string) $i, 5, '0', STR_PAD_LEFT), 'LIQUIDADO');
        }
        $this->contacto('529999999999', 'BURO');

        $tag = Tag::create(['name' => 'Renovacion Septiembre']);

        $res = $this->actingAs($this->operator)->postJson('/api/contacts/tags/bulk-attach-filtered', [
            'portfolio_status' => 'LIQUIDADO',
            'attach_tag_id'    => $tag->id,
        ]);

        $res->assertOk();
        $this->assertSame(120, $res->json('data.attached'));
        $this->assertSame(120, $tag->contacts()->count());
    }

    public function test_el_etiquetado_masivo_respeta_los_demas_filtros(): void
    {
        $this->contacto('529000000001', 'LIQUIDADO');
        $this->contacto('529000000002', 'LIQUIDADO', 'opted_out');

        $tag = Tag::create(['name' => 'Renovacion']);

        $this->actingAs($this->operator)->postJson('/api/contacts/tags/bulk-attach-filtered', [
            'portfolio_status' => 'LIQUIDADO',
            'status'           => 'active',
            'attach_tag_id'    => $tag->id,
        ])->assertOk();

        $this->assertSame(1, $tag->contacts()->count());
    }

    public function test_etiquetar_dos_veces_no_duplica_ni_recuenta(): void
    {
        $this->contacto('529000000001', 'LIQUIDADO');

        $tag = Tag::create(['name' => 'Renovacion']);

        $this->actingAs($this->operator)->postJson('/api/contacts/tags/bulk-attach-filtered', [
            'portfolio_status' => 'LIQUIDADO', 'attach_tag_id' => $tag->id,
        ]);

        $segunda = $this->actingAs($this->operator)->postJson('/api/contacts/tags/bulk-attach-filtered', [
            'portfolio_status' => 'LIQUIDADO', 'attach_tag_id' => $tag->id,
        ]);

        $this->assertSame(0, $segunda->json('data.attached'));
        $this->assertSame(1, $tag->contacts()->count());
    }

    public function test_el_etiquetado_masivo_no_pisa_las_etiquetas_que_ya_tenia(): void
    {
        $contacto = $this->contacto('529000000001', 'LIQUIDADO');
        $vieja    = Tag::create(['name' => 'VIP']);
        $contacto->tags()->attach($vieja->id);

        $nueva = Tag::create(['name' => 'Renovacion']);

        $this->actingAs($this->operator)->postJson('/api/contacts/tags/bulk-attach-filtered', [
            'portfolio_status' => 'LIQUIDADO', 'attach_tag_id' => $nueva->id,
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            ['VIP', 'Renovacion'],
            $contacto->fresh()->tags->pluck('name')->all()
        );
    }

    public function test_la_etiqueta_que_se_pone_no_se_confunde_con_el_filtro_por_etiqueta(): void
    {
        // Bug real encontrado al probar: `tag_id` significaba dos cosas a la vez - "filtra
        // los que YA tienen esta etiqueta" y "ponles esta etiqueta". El filtro ganaba y no
        // etiquetaba a nadie, sin error. Por eso la etiqueta a poner va en `attach_tag_id`.
        // De paso queda habilitado el caso util: "a los que tienen VIP, ponles Renovacion".
        $contacto = $this->contacto('529000000001', 'LIQUIDADO');
        $this->contacto('529000000002', 'LIQUIDADO');

        $vip = Tag::create(['name' => 'VIP']);
        $contacto->tags()->attach($vip->id);

        $nueva = Tag::create(['name' => 'Renovacion']);

        $res = $this->actingAs($this->operator)->postJson('/api/contacts/tags/bulk-attach-filtered', [
            'tag_id'        => $vip->id,     // filtro: solo los que ya son VIP
            'attach_tag_id' => $nueva->id,   // acción: ponles Renovacion
        ]);

        $res->assertOk();
        $this->assertSame(1, $res->json('data.attached'));
        $this->assertSame(1, $nueva->contacts()->count());
    }

    public function test_sin_filtros_etiqueta_toda_la_base(): void
    {
        // Es peligroso pero legitimo: el preview le muestra el total antes de confirmar.
        $this->contacto('529000000001', 'LIQUIDADO');
        $this->contacto('529000000002', null);

        $tag = Tag::create(['name' => 'Todos']);

        $res = $this->actingAs($this->operator)
                    ->postJson('/api/contacts/tags/bulk-attach-filtered', ['attach_tag_id' => $tag->id]);

        $this->assertSame(2, $res->json('data.attached'));
    }

    public function test_exige_una_etiqueta_existente(): void
    {
        $this->actingAs($this->operator)
             ->postJson('/api/contacts/tags/bulk-attach-filtered', ['attach_tag_id' => 9999])
             ->assertStatus(422);
    }

    public function test_un_agente_no_puede_etiquetar_en_masa(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $tag   = Tag::create(['name' => 'Renovacion']);

        $this->actingAs($agent)
             ->postJson('/api/contacts/tags/bulk-attach-filtered', ['attach_tag_id' => $tag->id])
             ->assertForbidden();
    }

    // ── El flujo completo, de punta a punta ──────────────────────────────────

    public function test_flujo_completo_filtrar_etiquetar_y_segmentar(): void
    {
        // Es el motivo de todo el modulo: filtrar por estado, etiquetar y de ahi salir a
        // campana. La campana sigue segmentando por etiqueta, no por estado de cartera.
        $this->contacto('529000000001', 'LIQUIDADO');
        $this->contacto('529000000002', 'LIQUIDADO');
        $this->contacto('529000000003', 'BURO');

        $tag = Tag::create(['name' => 'Renovacion Septiembre']);

        $preview = $this->actingAs($this->operator)
            ->postJson('/api/contacts/tags/bulk-preview', ['portfolio_status' => 'LIQUIDADO']);
        $this->assertSame(2, $preview->json('data.total'));

        $this->actingAs($this->operator)->postJson('/api/contacts/tags/bulk-attach-filtered', [
            'portfolio_status' => 'LIQUIDADO', 'attach_tag_id' => $tag->id,
        ])->assertOk();

        $porEtiqueta = $this->actingAs($this->operator)
            ->getJson('/api/contacts?tag_id=' . $tag->id);

        $this->assertSame(2, $porEtiqueta->json('total'));
    }
}
