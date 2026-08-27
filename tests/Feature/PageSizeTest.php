<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Support\PageSize;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Selector "Mostrar N registros" - el tamaño de página lo elige el operador.
 * Lo que se protege aquí: que un valor fuera del catálogo no tumbe el servidor
 * y que "Todos" tenga tope duro (el navegador no aguanta 200k filas).
 */
class PageSizeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    private function makeContacts(int $n): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = [
                'phone'      => '52923' . str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'name'       => "Contacto {$i}",
                'status'     => 'active',
                'source'     => 'manual',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        Contact::insert($rows);
    }

    public function test_per_page_respeta_el_valor_del_catalogo(): void
    {
        $this->makeContacts(30);

        $res = $this->getJson('/api/contacts?per_page=10')->assertOk();

        $this->assertCount(10, $res->json('data'));
        $this->assertSame(10, $res->json('per_page'));
    }

    public function test_per_page_fuera_del_catalogo_cae_al_default(): void
    {
        $this->makeContacts(5);

        // 999999 a mano no debe traer todo: cae al default de contactos (50).
        $res = $this->getJson('/api/contacts?per_page=999999')->assertOk();

        $this->assertSame(50, $res->json('per_page'));
    }

    public function test_todos_trae_todo_cuando_cabe_en_el_tope(): void
    {
        $this->makeContacts(120);

        $res = $this->getJson('/api/contacts?per_page=all')->assertOk();

        $this->assertCount(120, $res->json('data'));
        $this->assertSame(PageSize::ALL_CAP, $res->json('per_page'));
        $this->assertFalse($res->json('capped'));
    }

    public function test_todos_avisa_cuando_recorta(): void
    {
        // No se crean 5001 contactos (lento): se comprueba la regla del helper, que es
        // lo que decide el aviso. El endpoint ya se cubre arriba.
        $request = \Illuminate\Http\Request::create('/api/contacts', 'GET', ['per_page' => 'all']);

        $this->assertSame(PageSize::ALL_CAP, PageSize::from($request, 50));
        $this->assertTrue(PageSize::wasCapped($request, PageSize::ALL_CAP + 1));
        $this->assertFalse(PageSize::wasCapped($request, PageSize::ALL_CAP));
    }

    public function test_sin_per_page_usa_el_default_de_cada_endpoint(): void
    {
        $this->makeContacts(5);

        $this->getJson('/api/contacts')->assertOk()->assertJsonPath('per_page', 50);
        $this->getJson('/api/campaigns')->assertOk()->assertJsonPath('meta.per_page', 20);
        $this->getJson('/api/dashboard/messages')->assertOk()->assertJsonPath('meta.per_page', 20);
    }

    public function test_campanas_y_mensajes_aceptan_per_page(): void
    {
        $this->getJson('/api/campaigns?per_page=100')->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/dashboard/messages?per_page=250')->assertOk()->assertJsonPath('meta.per_page', 250);
        $this->getJson('/api/sms/inbound?per_page=50')->assertOk()->assertJsonPath('meta.per_page', 50);
    }
}
