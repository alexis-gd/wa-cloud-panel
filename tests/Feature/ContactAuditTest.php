<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactSyncRun;
use App\Models\User;
use App\Services\Contacts\BulkInsert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Rastro de quien toca los contactos, y que una fila mala no tire la carga entera.
 *
 * Los contactos son el activo del negocio y hasta ahora la tabla solo decia DE DONDE salio cada
 * uno (`source`), nunca QUIEN. Con varios operadores subiendo Excel y borrando filas, "quien
 * borro a este cliente" no tenia respuesta en ningun lado.
 */
class ContactAuditTest extends TestCase
{
    use RefreshDatabase;

    // -- Quien lo dio de alta ---------------------------------------------------

    public function test_el_alta_manual_guarda_quien_lo_subio(): void
    {
        $usuario = User::factory()->create(['role' => 'operator']);
        $this->actingAs($usuario);

        $this->postJson('/api/contacts', ['phone' => '9231311146', 'name' => 'Prueba'])
             ->assertStatus(201);

        $this->assertSame($usuario->id, Contact::first()->created_by);
    }

    public function test_el_excel_guarda_quien_lo_subio(): void
    {
        $usuario = User::factory()->create(['role' => 'operator']);
        $this->actingAs($usuario);

        $archivo = UploadedFile::fake()->createWithContent(
            'contactos.csv',
            "telefono,nombre\n9231311146,Del Excel\n",
        );

        $this->post('/api/contacts/upload', ['file' => $archivo], ['Accept' => 'application/json'])
             ->assertStatus(200);

        $this->assertSame($usuario->id, Contact::first()->created_by);
    }

    /** Nulo significa algo: lo puso el cron del API del cliente, no una persona. */
    public function test_lo_que_sube_el_sistema_queda_sin_autor(): void
    {
        Contact::create(['phone' => '529231311146', 'status' => 'active', 'source' => 'api']);

        $this->assertNull(Contact::first()->created_by);
    }

    // -- Quien lo dio de baja ---------------------------------------------------

    public function test_el_borrado_guarda_quien_lo_borro(): void
    {
        $contacto = Contact::factory()->create();
        $usuario  = User::factory()->create(['role' => 'admin']);
        $this->actingAs($usuario);

        $this->deleteJson("/api/contacts/{$contacto->id}")->assertStatus(200);

        $borrado = Contact::withTrashed()->find($contacto->id);

        $this->assertNotNull($borrado->deleted_at);
        $this->assertSame($usuario->id, $borrado->deleted_by);
    }

    /** Si el usuario se borra, el contacto se queda: lo que se pierde es el nombre del autor. */
    public function test_borrar_al_usuario_no_se_lleva_al_contacto(): void
    {
        $usuario  = User::factory()->create(['role' => 'operator']);
        $contacto = Contact::factory()->create(['created_by' => $usuario->id]);

        $usuario->delete();

        $this->assertDatabaseHas('contacts', ['id' => $contacto->id]);
        $this->assertNull($contacto->fresh()->created_by);
    }

    public function test_el_excel_de_contactos_trae_la_columna_de_quien_lo_subio(): void
    {
        $usuario = User::factory()->create(['role' => 'operator', 'name' => 'Joseph']);
        Contact::factory()->create(['created_by' => $usuario->id]);

        $this->actingAsAdmin();

        $this->get('/api/export/contacts')
             ->assertStatus(200)
             ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // -- Una fila mala no tira la carga -----------------------------------------

    /**
     * `contacts.name` es varchar(100). Antes, un nombre mas largo en UNA fila hacia que MySQL
     * rechazara el INSERT completo: de 500 contactos no entraba ninguno, con una excepcion
     * cruda y sin decir cual fila fue.
     */
    public function test_un_nombre_larguisimo_ya_no_tumba_el_lote(): void
    {
        $r = BulkInsert::insertar([
            ['phone' => '529231311146', 'name' => str_repeat('A', 400), 'status' => 'active',
             'source' => 'api', 'created_at' => now(), 'updated_at' => now()],
            ['phone' => '525512345678', 'name' => 'Normal', 'status' => 'active',
             'source' => 'api', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame(2, $r['inserted']);
        $this->assertEmpty($r['rejected']);
        $this->assertDatabaseCount('contacts', 2);

        // El nombre entra recortado, no truncado por MySQL ni perdido.
        $this->assertSame(BulkInsert::MAX_NOMBRE, mb_strlen(Contact::where('phone', '529231311146')->value('name')));
    }

    public function test_las_filas_buenas_entran_aunque_una_sea_imposible(): void
    {
        // Un telefono nulo no pasa la restriccion de la columna; lo demas si.
        $r = BulkInsert::insertar([
            ['phone' => null, 'name' => 'Rota', 'status' => 'active',
             'source' => 'api', 'created_at' => now(), 'updated_at' => now()],
            ['phone' => '525512345678', 'name' => 'Buena', 'status' => 'active',
             'source' => 'api', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame(1, $r['inserted']);
        $this->assertCount(1, $r['rejected']);
        $this->assertDatabaseHas('contacts', ['phone' => '525512345678']);
    }

    // -- Historial del cron -----------------------------------------------------

    /**
     * El Setting `contact_sync_last_run` solo guarda la ultima y se sobreescribe. Si el cron
     * falla hoy, el rastro de la corrida buena de ayer se pierde justo cuando hace falta
     * compararlas - y este cron ya estuvo tres dias muerto sin que nadie lo notara.
     */
    public function test_el_historial_guarda_una_fila_por_corrida(): void
    {
        ContactSyncRun::create(['ok' => true, 'received' => 100, 'inserted' => 10]);
        ContactSyncRun::create(['ok' => false, 'error' => 'El API no responde']);

        $this->assertDatabaseCount('contact_sync_runs', 2);
        $this->assertSame(2, ContactSyncRun::recientes(20)->get()->count());
    }

    public function test_el_comando_muestra_el_historial(): void
    {
        ContactSyncRun::create(['ok' => true, 'received' => 14872, 'inserted' => 8729, 'refreshed' => 6097]);

        $this->artisan('contactos:ultima-corrida --historial')
             ->expectsOutputToContain('14872')
             ->assertSuccessful();
    }

    public function test_sin_corridas_el_comando_lo_dice_sin_reventar(): void
    {
        $this->artisan('contactos:ultima-corrida --historial')
             ->expectsOutputToContain('Todavia no hay corridas')
             ->assertSuccessful();
    }
}
