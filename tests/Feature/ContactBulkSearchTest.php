<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Services\Contacts\PhoneListParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pegado masivo de números en el buscador de Contactos.
 * El operador copia una columna de Excel (cientos de números) y la pega; el sistema
 * filtra la lista y le dice cuáles no están dados de alta.
 */
class ContactBulkSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdmin();
    }

    private function contact(string $phone, array $attrs = []): Contact
    {
        return Contact::create(array_merge([
            'phone'  => $phone,
            'name'   => 'Prueba',
            'status' => 'active',
            'source' => 'manual',
        ], $attrs));
    }

    public function test_filtra_por_la_lista_pegada_e_ignora_el_resto(): void
    {
        $this->contact('529231111111');
        $this->contact('529232222222');
        $this->contact('529233333333'); // no va en el pegado

        $res = $this->postJson('/api/contacts/search', [
            'phones_raw' => "529231111111 529232222222",
        ])->assertOk();

        $phones = collect($res->json('data'))->pluck('phone')->sort()->values()->all();

        $this->assertSame(['529231111111', '529232222222'], $phones);
        $this->assertSame(2, $res->json('total'));
    }

    public function test_acepta_saltos_de_linea_tabuladores_comas_y_formatos_sueltos(): void
    {
        $this->contact('529231111111');
        $this->contact('529232222222');
        $this->contact('529233333333');

        // Tal cual sale de Excel y del copiado a mano: saltos, tabulador, coma,
        // 10 dígitos locales, con + y con paréntesis/guiones.
        $raw = "529231111111\n\t9232222222,\n+52 923 333-3333";

        $res = $this->postJson('/api/contacts/search', ['phones_raw' => $raw])->assertOk();

        $this->assertSame(3, $res->json('total'));
        $this->assertSame(3, $res->json('paste.valid'));
        $this->assertSame(0, $res->json('paste.invalid'));
    }

    public function test_acepta_numeros_formateados_con_espacios_uno_por_linea(): void
    {
        $this->contact('529231111111');
        $this->contact('529232222222');

        // Como los formatea Excel: el espacio va DENTRO del número, no entre números.
        $raw = "52 923 111 1111
52 923 222 2222";

        $res = $this->postJson('/api/contacts/search', ['phones_raw' => $raw])->assertOk();

        $this->assertSame(2, $res->json('total'));
        $this->assertSame(0, $res->json('paste.invalid'));
    }

    public function test_reporta_los_numeros_que_no_estan_en_el_sistema(): void
    {
        $this->contact('529231111111');

        $res = $this->postJson('/api/contacts/search', [
            'phones_raw' => '529231111111 529234444444 529235555555',
        ])->assertOk();

        $this->assertSame(1, $res->json('paste.found'));
        $this->assertSame(2, $res->json('paste.missing_count'));
        $this->assertEqualsCanonicalizing(
            ['529234444444', '529235555555'],
            $res->json('paste.missing')
        );
    }

    public function test_los_faltantes_no_dependen_de_los_demas_filtros(): void
    {
        // El número existe pero está de baja: con el filtro de Activos no sale en la tabla,
        // pero "no está en el sistema" es otra cosa - no debe contarse como faltante.
        $this->contact('529231111111', ['status' => 'opted_out']);

        $res = $this->postJson('/api/contacts/search', [
            'phones_raw' => '529231111111',
            'status'     => 'active',
        ])->assertOk();

        $this->assertSame(0, $res->json('total'));
        $this->assertSame(1, $res->json('paste.found'));
        $this->assertSame(0, $res->json('paste.missing_count'));
    }

    public function test_cuenta_los_invalidos_de_formato_sin_reventar(): void
    {
        $this->contact('529231111111');

        $res = $this->postJson('/api/contacts/search', [
            'phones_raw' => '529231111111 123 abc 55',
        ])->assertOk();

        $this->assertSame(1, $res->json('total'));
        $this->assertSame(3, $res->json('paste.invalid'));
        $this->assertSame(4, $res->json('paste.pasted'));
    }

    public function test_deduplica_numeros_repetidos_en_el_pegado(): void
    {
        $this->contact('529231111111');

        // El mismo número escrito de tres formas: es uno solo tras normalizar.
        $res = $this->postJson('/api/contacts/search', [
            'phones_raw' => "529231111111 9231111111 +52 923 111 1111",
        ])->assertOk();

        $this->assertSame(1, $res->json('total'));
        $this->assertSame(1, $res->json('paste.valid'));
        $this->assertCount(1, $res->json('data'));
    }

    public function test_pegado_de_500_numeros_responde_completo(): void
    {
        // El caso real que reportó el cliente: 500 números de golpe.
        $phones = [];
        for ($i = 0; $i < 500; $i++) {
            $phones[] = '52923' . str_pad((string) $i, 7, '0', STR_PAD_LEFT);
        }

        $rows = [];
        foreach (array_slice($phones, 0, 300) as $phone) {
            $rows[] = [
                'phone' => $phone, 'name' => null, 'status' => 'active',
                'source' => 'import', 'created_at' => now(), 'updated_at' => now(),
            ];
        }
        Contact::insert($rows);

        $res = $this->postJson('/api/contacts/search', [
            'phones_raw' => implode(' ', $phones),
            'per_page'   => 'all',
        ])->assertOk();

        $this->assertSame(300, $res->json('total'));
        $this->assertCount(300, $res->json('data'));
        $this->assertSame(200, $res->json('paste.missing_count'));
    }

    public function test_el_pegado_manda_sobre_el_buscador_de_texto(): void
    {
        $this->contact('529231111111', ['name' => 'Juan']);
        $this->contact('529232222222', ['name' => 'Pedro']);

        // q no debe recortar la lista pegada: el operador ya dijo qué quiere ver.
        $res = $this->postJson('/api/contacts/search', [
            'phones_raw' => '529231111111 529232222222',
            'q'          => 'Juan',
        ])->assertOk();

        $this->assertSame(2, $res->json('total'));
    }

    public function test_pegado_sin_numeros_validos_devuelve_vacio_con_el_motivo(): void
    {
        $this->contact('529231111111');

        $res = $this->postJson('/api/contacts/search', ['phones_raw' => 'abc def'])->assertOk();

        $this->assertSame(0, $res->json('total'));
        $this->assertSame(2, $res->json('paste.invalid'));
    }

    public function test_el_parser_topa_el_pegado(): void
    {
        $raw = implode(' ', array_map(
            fn ($i) => '52923' . str_pad((string) $i, 7, '0', STR_PAD_LEFT),
            range(0, PhoneListParser::MAX_PHONES + 10)
        ));

        $parsed = PhoneListParser::parse($raw);

        $this->assertTrue($parsed['truncated']);
        $this->assertCount(PhoneListParser::MAX_PHONES, $parsed['phones']);
    }

    public function test_el_get_de_contactos_sigue_sin_resumen_de_pegado(): void
    {
        $this->contact('529231111111');

        $this->getJson('/api/contacts')->assertOk()->assertJsonPath('paste', null);
    }
}
