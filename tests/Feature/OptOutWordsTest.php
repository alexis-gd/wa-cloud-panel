<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Services\OptOutWords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lista de baja acordada con el cliente: `STOP` y `DAR DE BAJA`, nada más.
 *
 * `NO` salió porque dio de baja a un contacto que ya había aceptado y solo estaba
 * contestando la pregunta de un agente. `BAJA` y `CANCELAR` salieron por decisión del
 * cliente. Estos tests fijan la lista para que nadie la vuelva a ampliar sin querer.
 */
class OptOutWordsTest extends TestCase
{
    use RefreshDatabase;

    /** @dataProvider palabrasQueDanDeBaja */
    public function test_las_palabras_acordadas_dan_de_baja(string $texto): void
    {
        $this->assertTrue(OptOutWords::matches($texto), "'{$texto}' debería dar de baja.");
    }

    public static function palabrasQueDanDeBaja(): array
    {
        return [
            ['STOP'], ['stop'], ['Stop'], [' STOP '], ['stop.'],
            ['DAR DE BAJA'], ['dar de baja'], ['Dar de Baja'], ['dar de baja.'],
            ['dar  de  baja'],
        ];
    }

    /** @dataProvider palabrasQueYaNoDanDeBaja */
    public function test_las_palabras_retiradas_ya_no_dan_de_baja(string $texto): void
    {
        $this->assertFalse(OptOutWords::matches($texto), "'{$texto}' ya no debe dar de baja.");
    }

    public static function palabrasQueYaNoDanDeBaja(): array
    {
        return [
            ['NO'], ['no'], ['No'],
            ['BAJA'], ['baja'],
            ['CANCELAR'], ['cancelar'],
        ];
    }

    /** @dataProvider frasesQueContienenLaPalabra */
    public function test_una_frase_que_contiene_la_palabra_no_da_de_baja(string $texto): void
    {
        $this->assertFalse(OptOutWords::matches($texto), "'{$texto}' no debe dar de baja.");
    }

    public static function frasesQueContienenLaPalabra(): array
    {
        return [
            ['no quiero dar de baja mi credito'],
            ['me pueden dar de baja el seguro?'],
            ['stop es en ingles'],
            ['aun no'],
            ['no gracias'],
        ];
    }

    // ── El caso real de produccion ───────────────────────────────────────────

    public function test_contestar_no_a_un_agente_ya_no_da_de_baja(): void
    {
        $contact = Contact::factory()->create(['status' => 'active']);
        $payload = $this->textoEntrante($contact->phone, 'No');

        $this->postJson('/api/webhook', $payload, $this->firmaValida($payload))->assertOk();

        $this->assertSame('active', $contact->fresh()->status);
    }

    public function test_stop_sigue_dando_de_baja(): void
    {
        $contact = Contact::factory()->create(['status' => 'active']);
        $payload = $this->textoEntrante($contact->phone, 'STOP');

        $this->postJson('/api/webhook', $payload, $this->firmaValida($payload))->assertOk();

        $this->assertSame('opted_out', $contact->fresh()->status);
    }

    public function test_dar_de_baja_da_de_baja(): void
    {
        $contact = Contact::factory()->create(['status' => 'active']);
        $payload = $this->textoEntrante($contact->phone, 'Dar de baja');

        $this->postJson('/api/webhook', $payload, $this->firmaValida($payload))->assertOk();

        $this->assertSame('opted_out', $contact->fresh()->status);
    }

    // ── Comando de reversion ─────────────────────────────────────────────────

    public function test_el_comando_reactiva_a_quien_dijo_no(): void
    {
        $contact = $this->contactoDadoDeBajaPor('No');

        $this->artisan('contacts:undo-optout', ['--word' => 'NO'])
            ->expectsConfirmation('¿Reactivar 1 contacto(s)?', 'yes')
            ->assertSuccessful();

        $contact->refresh();
        $this->assertSame('active', $contact->status);
        $this->assertNull($contact->opted_out_at);
        $this->assertNull($contact->opted_out_source);
    }

    public function test_el_comando_no_toca_a_quien_dijo_stop(): void
    {
        $contact = $this->contactoDadoDeBajaPor('STOP');

        $this->artisan('contacts:undo-optout', ['--word' => 'NO'])->assertSuccessful();

        $this->assertSame('opted_out', $contact->fresh()->status);
    }

    public function test_el_comando_no_toca_las_bajas_de_meta(): void
    {
        $contact = $this->contactoDadoDeBajaPor('No');
        $contact->update(['opted_out_source' => 'whatsapp_131050']);

        $this->artisan('contacts:undo-optout', ['--word' => 'NO'])->assertSuccessful();

        $this->assertSame('opted_out', $contact->fresh()->status);
    }

    public function test_dry_run_no_modifica_nada(): void
    {
        $contact = $this->contactoDadoDeBajaPor('No');

        $this->artisan('contacts:undo-optout', ['--word' => 'NO', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame('opted_out', $contact->fresh()->status);
    }

    public function test_el_comando_rechaza_revertir_una_palabra_vigente(): void
    {
        $this->artisan('contacts:undo-optout', ['--word' => 'STOP'])->assertFailed();
    }

    /**
     * El caso real: el contacto mandó "No" y enseguida "Aún no". Buscando solo el ÚLTIMO
     * mensaje quedaba fuera, porque el último era "Aún no".
     */
    public function test_encuentra_al_que_dijo_no_y_luego_escribio_otra_cosa(): void
    {
        $contact = $this->contactoDadoDeBajaPor('No');
        $this->mensajeEntrante($contact, 'Aún no');

        $this->artisan('contacts:undo-optout', ['--word' => 'NO'])
            ->expectsConfirmation('¿Reactivar 1 contacto(s)?', 'yes')
            ->assertSuccessful();

        $this->assertSame('active', $contact->fresh()->status);
    }

    public function test_no_reactiva_si_tambien_escribio_una_palabra_vigente(): void
    {
        $contact = $this->contactoDadoDeBajaPor('No');
        $this->mensajeEntrante($contact, 'STOP');

        $this->artisan('contacts:undo-optout', ['--word' => 'NO'])->assertSuccessful();

        $this->assertSame('opted_out', $contact->fresh()->status);
    }

    // ── Reactivacion por numero (auditoria manual) ───────────────────────────

    public function test_phone_reactiva_los_numeros_indicados(): void
    {
        $ana  = $this->contactoDadoDeBajaPor('No');
        $jose = $this->contactoDadoDeBajaPor('Aún no');
        $rosa = $this->contactoDadoDeBajaPor('Nel');

        $this->artisan('contacts:undo-optout', [
            '--phone' => "{$ana->phone},{$jose->phone},{$rosa->phone}",
        ])->expectsConfirmation('¿Reactivar 3 contacto(s)?', 'yes')->assertSuccessful();

        foreach ([$ana, $jose, $rosa] as $contact) {
            $this->assertSame('active', $contact->fresh()->status);
            $this->assertNull($contact->fresh()->opted_out_at);
        }
    }

    public function test_phone_no_toca_a_los_que_no_van_en_la_lista(): void
    {
        $reactivar = $this->contactoDadoDeBajaPor('No');
        $legitimo  = $this->contactoDadoDeBajaPor('STOP');

        $this->artisan('contacts:undo-optout', ['--phone' => $reactivar->phone])
            ->expectsConfirmation('¿Reactivar 1 contacto(s)?', 'yes')
            ->assertSuccessful();

        $this->assertSame('active',    $reactivar->fresh()->status);
        $this->assertSame('opted_out', $legitimo->fresh()->status);
    }

    public function test_phone_avisa_si_el_numero_no_esta_de_baja(): void
    {
        $activo = Contact::factory()->create(['status' => 'active']);

        $this->artisan('contacts:undo-optout', ['--phone' => $activo->phone])
            ->expectsOutputToContain('no está de baja')
            ->assertSuccessful();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function contactoDadoDeBajaPor(string $texto): Contact
    {
        $contact = Contact::factory()->create([
            'status'           => 'opted_out',
            'opted_out_at'     => now(),
            'opted_out_source' => 'auto',
        ]);

        $this->mensajeEntrante($contact, $texto);

        return $contact;
    }

    private function mensajeEntrante(Contact $contact, string $texto): void
    {
        Conversation::create([
            'contact_id'   => $contact->id,
            'direction'    => 'inbound',
            'message_type' => 'text',
            'body'         => $texto,
            'status'       => 'received',
            'window_open'  => true,
        ]);
    }

    private function textoEntrante(string $phone, string $texto): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'messages' => [[
                            'from' => $phone,
                            'id'   => 'wamid.' . uniqid(),
                            'type' => 'text',
                            'text' => ['body' => $texto],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function firmaValida(array $payload): array
    {
        $raw = json_encode($payload);

        return [
            'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $raw, config('services.whatsapp.app_secret')),
        ];
    }
}
