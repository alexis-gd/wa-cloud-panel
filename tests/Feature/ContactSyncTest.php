<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sincronización de contactos desde el API del cliente (C1).
 *
 * El API es de ellos y corre en su red, así que aquí NUNCA se toca de verdad: todo va con
 * `Http::fake()`, igual que Meta y el gateway SMS.
 *
 * Regla del cliente: **solo agregar nuevos**. Nada de actualizar ni reactivar, y menos a
 * quien pidió su baja.
 */
class ContactSyncTest extends TestCase
{
    use RefreshDatabase;

    private const URL       = 'http://192.168.17.20:8001/clients';
    private const LOGIN_URL = 'http://192.168.17.20:8001/login';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'contact_sync.url'         => self::URL,
            // Los tests de arriba usan `basic` por simplicidad; el bloque de login prueba
            // el modo real del API del cliente.
            'contact_sync.auth'        => 'basic',
            'contact_sync.user'        => 'sender',
            'contact_sync.password'    => 'secreto',
            'contact_sync.root'        => '',
            'contact_sync.field_phone' => 'telefono,celular,phone,numero',
            'contact_sync.field_name'  => 'nombre,name,cliente',
            'contact_sync.tag'         => null,
        ]);
    }

    private function responde(array $body, int $status = 200): void
    {
        Http::fake([self::URL => Http::response($body, $status)]);
    }

    private function sync(array $opciones = []): void
    {
        $this->artisan('contactos:sincronizar', $opciones);
    }

    // ── Alta ─────────────────────────────────────────────────────────────────

    public function test_da_de_alta_los_contactos_del_api(): void
    {
        $this->responde([
            ['telefono' => '9231311146', 'nombre' => 'Juan Pérez'],
            ['telefono' => '6692522844', 'nombre' => 'Ana López'],
        ]);

        $this->sync();

        $this->assertDatabaseHas('contacts', [
            'phone'  => '529231311146',
            'name'   => 'Juan Pérez',
            'status' => 'active',
            'source' => 'api',
        ]);
        $this->assertSame(2, Contact::count());
    }

    public function test_normaliza_los_telefonos_igual_que_el_resto_del_sistema(): void
    {
        $this->responde([
            ['telefono' => '(669) 252-2844', 'nombre' => 'Con formato'],
            ['telefono' => '5216692522845', 'nombre'  => 'Formato viejo con 1'],
        ]);

        $this->sync();

        $this->assertDatabaseHas('contacts', ['phone' => '526692522844']);
        $this->assertDatabaseHas('contacts', ['phone' => '526692522845']);
    }

    public function test_un_contacto_sin_nombre_entra_igual(): void
    {
        $this->responde([['telefono' => '9231311146']]);

        $this->sync();

        $this->assertDatabaseHas('contacts', ['phone' => '529231311146', 'name' => null]);
    }

    // ── Solo agregar nuevos ──────────────────────────────────────────────────

    public function test_no_duplica_a_quien_ya_existe(): void
    {
        Contact::factory()->create(['phone' => '529231311146', 'name' => 'Nombre del panel']);

        $this->responde([['telefono' => '9231311146', 'nombre' => 'Nombre del API']]);

        $this->sync();

        $this->assertSame(1, Contact::count());
        // No se actualiza: el panel manda sobre lo que traiga el API.
        $this->assertSame('Nombre del panel', Contact::first()->name);
    }

    public function test_no_revive_a_quien_pidio_su_baja(): void
    {
        // La baja manda sobre cualquier fuente externa: es cumplimiento, no preferencia.
        Contact::factory()->create(['phone' => '529231311146', 'status' => 'opted_out']);

        $this->responde([['telefono' => '9231311146', 'nombre' => 'Juan']]);

        $this->sync();

        $this->assertSame('opted_out', Contact::first()->status);
        $this->assertSame(1, Contact::count());
    }

    public function test_un_contacto_borrado_no_tumba_la_sincronizacion(): void
    {
        // `phone` es UNIQUE y un borrado sigue ocupando el número: sin `withTrashed()` el
        // insert revienta por llave duplicada y se cae toda la corrida.
        $c = Contact::factory()->create(['phone' => '529231311146']);
        $c->delete();

        $this->responde([
            ['telefono' => '9231311146', 'nombre' => 'Borrado'],
            ['telefono' => '6692522844', 'nombre' => 'Nuevo'],
        ]);

        $this->sync();

        $this->assertDatabaseHas('contacts', ['phone' => '526692522844']);
        $this->assertSame(1, Contact::withTrashed()->where('phone', '529231311146')->count());
    }

    public function test_el_mismo_telefono_repetido_en_la_respuesta_entra_una_vez(): void
    {
        $this->responde([
            ['telefono' => '9231311146', 'nombre' => 'Uno'],
            ['telefono' => '529231311146', 'nombre' => 'El mismo con lada'],
        ]);

        $this->sync();

        $this->assertSame(1, Contact::count());
    }

    public function test_una_fila_con_telefono_ilegible_no_detiene_a_las_demas(): void
    {
        $this->responde([
            ['telefono' => 'sin dato', 'nombre' => 'Malo'],
            ['telefono' => '9231311146', 'nombre' => 'Bueno'],
        ]);

        $this->sync();

        $this->assertSame(1, Contact::count());
        $this->assertDatabaseHas('contacts', ['phone' => '529231311146']);
    }

    // ── Modo seco ────────────────────────────────────────────────────────────

    public function test_el_modo_seco_no_escribe_nada(): void
    {
        $this->responde([['telefono' => '9231311146', 'nombre' => 'Juan']]);

        $this->artisan('contactos:sincronizar', ['--dry-run' => true])
             ->expectsOutputToContain('MODO SECO')
             ->assertSuccessful();

        $this->assertSame(0, Contact::count());
    }

    // ── Mapeo configurable ───────────────────────────────────────────────────

    public function test_lee_la_lista_dentro_de_la_respuesta_segun_la_configuracion(): void
    {
        config(['contact_sync.root' => 'result.items']);

        $this->responde(['result' => ['items' => [['telefono' => '9231311146']]]]);

        $this->sync();

        $this->assertSame(1, Contact::count());
    }

    public function test_acepta_nombres_de_campo_alternativos(): void
    {
        // El API puede llamarle `celular` en vez de `telefono`: se prueban en orden.
        $this->responde([['celular' => '9231311146', 'cliente' => 'Juan']]);

        $this->sync();

        $this->assertDatabaseHas('contacts', ['phone' => '529231311146', 'name' => 'Juan']);
    }

    public function test_no_distingue_mayusculas_en_los_nombres_de_campo(): void
    {
        $this->responde([['Telefono' => '9231311146', 'Nombre' => 'Juan']]);

        $this->sync();

        $this->assertDatabaseHas('contacts', ['phone' => '529231311146', 'name' => 'Juan']);
    }

    public function test_etiqueta_a_los_nuevos_si_hay_etiqueta_configurada(): void
    {
        config(['contact_sync.tag' => 'Del sistema']);

        $this->responde([['telefono' => '9231311146']]);

        $this->sync();

        $this->assertSame(['Del sistema'], Contact::first()->tags->pluck('name')->all());
    }

    // ── Fallos del API ───────────────────────────────────────────────────────

    public function test_si_el_api_no_responde_falla_sin_reventar(): void
    {
        Http::fake([self::URL => Http::response(null, 500)]);

        $this->artisan('contactos:sincronizar')->assertFailed();

        $this->assertSame(0, Contact::count());
    }

    public function test_si_las_credenciales_son_malas_falla_sin_reventar(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'Unauthorized'], 401)]);

        $this->artisan('contactos:sincronizar')->assertFailed();
    }

    public function test_si_no_hay_url_configurada_lo_dice(): void
    {
        config(['contact_sync.url' => null]);

        $this->artisan('contactos:sincronizar')->assertFailed();
    }

    public function test_si_la_respuesta_no_trae_una_lista_lo_dice(): void
    {
        // Un HTML de error o un JSON con otra forma: se avisa en vez de dar de alta 0 en silencio.
        $this->responde(['mensaje' => 'sin datos']);

        $this->artisan('contactos:sincronizar')->assertFailed();
        $this->assertSame(0, Contact::count());
    }

    public function test_una_lista_vacia_no_es_un_error(): void
    {
        $this->responde([]);

        $this->artisan('contactos:sincronizar')->assertSuccessful();
    }

    // ── Diagnóstico ──────────────────────────────────────────────────────────

    public function test_el_probador_muestra_los_campos_que_llegan(): void
    {
        $this->responde([['telefono' => '9231311146', 'nombre' => 'Juan', 'credito' => 5000]]);

        $this->artisan('contactos:probar-api')
             ->expectsOutputToContain('telefono, nombre, credito')
             ->assertSuccessful();
    }

    public function test_el_probador_no_imprime_la_contrasena(): void
    {
        $this->responde([['telefono' => '9231311146']]);

        $this->artisan('contactos:probar-api')
             ->doesntExpectOutputToContain('secreto')
             ->assertSuccessful();
    }

    public function test_el_probador_avisa_si_no_encuentra_la_lista(): void
    {
        $this->responde(['result' => ['items' => []]]);

        $this->artisan('contactos:probar-api')
             ->expectsOutputToContain('SYNC_API_ROOT')
             ->assertFailed();
    }

    public function test_el_probador_no_escribe_nada(): void
    {
        $this->responde([['telefono' => '9231311146']]);

        $this->artisan('contactos:probar-api')->assertSuccessful();

        $this->assertSame(0, Contact::count());
    }

    // ── Seguridad de la llamada ──────────────────────────────────────────────

    public function test_manda_las_credenciales_configuradas(): void
    {
        $this->responde([]);

        $this->sync();

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Basic ' . base64_encode('sender:secreto'));
        });
    }

    public function test_puede_autenticar_con_token(): void
    {
        // Si su API resulta ser por token, se cambia una variable del .env, no código.
        config(['contact_sync.auth' => 'bearer', 'contact_sync.token' => 'abc123']);

        $this->responde([]);

        $this->sync();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer abc123'));
    }

    // ── Estado del cliente en SU sistema (LIQUIDADO / BURO / BAJA) ───────────

    /** Respuesta con la forma REAL del API del cliente. */
    private function respuestaReal(): array
    {
        return ['success' => true, 'data' => [
            ['Celular' => '6692406890', 'Nombre' => 'PATRICIA MARIA RIVERA PAZ', 'Estado' => 'BAJA'],
            ['Celular' => '6691655905', 'Nombre' => 'CARLOS OSUNA VEGA',         'Estado' => 'LIQUIDADO'],
            ['Celular' => '6699931652', 'Nombre' => 'PERLA TERESA ORTIZ GOMEZ',  'Estado' => 'BURO'],
        ]];
    }

    public function test_lee_la_respuesta_real_del_cliente(): void
    {
        // Forma exacta que devuelve su API: envoltura `data`, campos con mayuscula inicial.
        config(['contact_sync.root' => 'data']);

        $this->responde($this->respuestaReal());

        $this->sync();

        $this->assertSame(3, Contact::count());
        $this->assertDatabaseHas('contacts', [
            'phone'  => '526692406890',
            'name'   => 'PATRICIA MARIA RIVERA PAZ',
            'source' => 'api',
        ]);
    }

    public function test_etiqueta_a_cada_contacto_con_su_estado(): void
    {
        // Asi el operador puede mandar renovacion solo a los LIQUIDADO sin cruzar listas.
        config(['contact_sync.root' => 'data']);

        $this->responde($this->respuestaReal());

        $this->sync();

        $liquidado = Contact::where('phone', '526691655905')->firstOrFail();

        $this->assertSame(['LIQUIDADO'], $liquidado->tags->pluck('name')->all());
        $this->assertEqualsCanonicalizing(['BAJA', 'BURO', 'LIQUIDADO'], Tag::pluck('name')->all());
    }

    public function test_la_baja_de_SU_sistema_no_es_nuestra_baja(): void
    {
        // Su "BAJA" significa que termino su relacion con ellos, NO que la persona pidio
        // dejar de recibir mensajes. Entra como contacto activo, solo etiquetado.
        config(['contact_sync.root' => 'data']);

        $this->responde($this->respuestaReal());

        $this->sync();

        $baja = Contact::where('phone', '526692406890')->firstOrFail();

        $this->assertSame('active', $baja->status);
        $this->assertSame(['BAJA'], $baja->tags->pluck('name')->all());
    }

    public function test_por_default_no_excluye_a_nadie(): void
    {
        // Descartar en silencio seria peor que dar de alta de mas: la decision es del cliente.
        config(['contact_sync.root' => 'data']);

        $this->responde($this->respuestaReal());

        $this->sync();

        $this->assertSame(3, Contact::count());
    }

    public function test_se_pueden_excluir_estados(): void
    {
        config(['contact_sync.root' => 'data', 'contact_sync.status_exclude' => 'BURO,BAJA']);

        $this->responde($this->respuestaReal());

        $this->sync();

        $this->assertSame(1, Contact::count());
        $this->assertDatabaseHas('contacts', ['phone' => '526691655905']);
    }

    public function test_se_puede_dar_de_alta_solo_ciertos_estados(): void
    {
        config(['contact_sync.root' => 'data', 'contact_sync.status_include' => 'LIQUIDADO']);

        $this->responde($this->respuestaReal());

        $this->sync();

        $this->assertSame(1, Contact::count());
        $this->assertDatabaseHas('contacts', ['phone' => '526691655905']);
    }

    public function test_excluir_gana_sobre_incluir(): void
    {
        // La regla mas restrictiva manda: es la que protege.
        config([
            'contact_sync.root'           => 'data',
            'contact_sync.status_include' => 'LIQUIDADO,BURO',
            'contact_sync.status_exclude' => 'BURO',
        ]);

        $this->responde($this->respuestaReal());

        $this->sync();

        $this->assertSame(1, Contact::count());
        $this->assertDatabaseHas('contacts', ['phone' => '526691655905']);
    }

    public function test_el_filtro_de_estado_no_distingue_mayusculas(): void
    {
        config(['contact_sync.root' => 'data', 'contact_sync.status_exclude' => 'buro']);

        $this->responde($this->respuestaReal());

        $this->sync();

        $this->assertSame(2, Contact::count());
    }

    public function test_se_puede_apagar_el_etiquetado_por_estado(): void
    {
        config(['contact_sync.root' => 'data', 'contact_sync.tag_from_status' => false]);

        $this->responde($this->respuestaReal());

        $this->sync();

        $this->assertSame(0, Tag::count());
    }

    public function test_el_resumen_desglosa_por_estado(): void
    {
        config(['contact_sync.root' => 'data']);

        $this->responde($this->respuestaReal());

        // El desglose es lo que permite decidir con el cliente a quien si ofrecerle.
        $this->artisan('contactos:sincronizar', ['--dry-run' => true])
             ->expectsOutputToContain('LIQUIDADO')
             ->expectsOutputToContain('BURO')
             ->assertSuccessful();
    }

    public function test_una_respuesta_sin_campo_de_estado_sigue_funcionando(): void
    {
        $this->responde([['telefono' => '9231311146', 'nombre' => 'Juan']]);

        $this->sync();

        $this->assertSame(1, Contact::count());
        $this->assertSame(0, Tag::count());
    }

    public function test_el_probador_lista_los_estados_que_trae_el_api(): void
    {
        config(['contact_sync.root' => 'data']);

        $this->responde($this->respuestaReal());

        $this->artisan('contactos:probar-api')
             ->expectsOutputToContain('LIQUIDADO')
             ->assertSuccessful();
    }

    // ── Login con JWT (el modo real del API del cliente) ─────────────────────

    /** Deja la config en el modo `login`, como el API real del cliente. */
    private function modoLogin(): void
    {
        config([
            'contact_sync.auth'           => 'login',
            'contact_sync.login_url'      => self::LOGIN_URL,
            'contact_sync.login_user_key' => 'usuario',
            'contact_sync.login_pass_key' => 'password',
            'contact_sync.token_path'     => 'token',
        ]);
    }

    public function test_hace_login_y_usa_el_token_en_la_consulta(): void
    {
        // El JWT de este API caduca en 5 minutos, asi que se pide uno en CADA corrida en
        // vez de guardarlo en el .env: un token fijo caducaria antes del siguiente cron.
        $this->modoLogin();

        Http::fake([
            self::LOGIN_URL => Http::response(['success' => true, 'token' => 'jwt-de-prueba']),
            self::URL       => Http::response([['telefono' => '9231311146']]),
        ]);

        $this->sync();

        Http::assertSent(fn ($r) => $r->url() === self::LOGIN_URL
            && $r['usuario'] === 'sender'
            && $r['password'] === 'secreto');

        Http::assertSent(fn ($r) => $r->url() === self::URL
            && $r->hasHeader('Authorization', 'Bearer jwt-de-prueba'));

        $this->assertSame(1, Contact::count());
    }

    public function test_si_el_login_falla_no_consulta_los_datos(): void
    {
        $this->modoLogin();

        Http::fake([
            self::LOGIN_URL => Http::response(['success' => false], 401),
            self::URL       => Http::response([['telefono' => '9231311146']]),
        ]);

        $this->artisan('contactos:sincronizar')->assertFailed();

        // No tiene caso pedir los datos sin token: el error del login es el que explica.
        Http::assertNotSent(fn ($r) => $r->url() === self::URL);
        $this->assertSame(0, Contact::count());
    }

    public function test_si_el_login_responde_sin_token_lo_dice(): void
    {
        $this->modoLogin();

        Http::fake([
            self::LOGIN_URL => Http::response(['success' => true]),   // 200 pero sin token
            self::URL       => Http::response([]),
        ]);

        $this->artisan('contactos:sincronizar')->assertFailed();
        Http::assertNotSent(fn ($r) => $r->url() === self::URL);
    }

    public function test_el_token_se_puede_leer_de_otra_llave(): void
    {
        $this->modoLogin();
        config(['contact_sync.token_path' => 'data.access_token']);

        Http::fake([
            self::LOGIN_URL => Http::response(['data' => ['access_token' => 'otro-jwt']]),
            self::URL       => Http::response([]),
        ]);

        $this->sync();

        Http::assertSent(fn ($r) => $r->url() === self::URL
            && $r->hasHeader('Authorization', 'Bearer otro-jwt'));
    }

    public function test_los_nombres_del_login_son_configurables(): void
    {
        // Este API espera `usuario`; otro podria esperar `username`.
        $this->modoLogin();
        config(['contact_sync.login_user_key' => 'username', 'contact_sync.login_pass_key' => 'pwd']);

        Http::fake([
            self::LOGIN_URL => Http::response(['token' => 'jwt']),
            self::URL       => Http::response([]),
        ]);

        $this->sync();

        Http::assertSent(fn ($r) => $r->url() === self::LOGIN_URL
            && $r['username'] === 'sender' && $r['pwd'] === 'secreto');
    }

    public function test_el_probador_no_imprime_el_token_completo(): void
    {
        $this->modoLogin();

        Http::fake([
            self::LOGIN_URL => Http::response(['token' => 'jwt-secreto-completo-que-no-debe-salir']),
            self::URL       => Http::response([['telefono' => '9231311146']]),
        ]);

        $this->artisan('contactos:probar-api')
             ->doesntExpectOutputToContain('jwt-secreto-completo-que-no-debe-salir')
             ->assertSuccessful();
    }
}
