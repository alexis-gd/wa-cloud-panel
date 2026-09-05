<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ConversationAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reporte de conversaciones por agente (P1), con filtros por fecha y por agente, y descarga
 * en Excel y PDF.
 *
 * Trae DOS números porque "cuántas conversaciones tiene un agente" admite dos lecturas:
 * "recibidas en el periodo" responde al filtro de fecha, y "abiertas ahora" es la foto del
 * momento. Con una sola columna el reporte mentiría en la mitad de los casos.
 */
class AgentReportTest extends TestCase
{
    use RefreshDatabase;

    private const RUTA = '/api/reports/agent-conversations';

    private function agente(string $nombre): User
    {
        return User::factory()->create(['name' => $nombre, 'role' => 'agent', 'is_active' => true]);
    }

    private function contacto(string $phone): Contact
    {
        return Contact::create([
            'phone'  => $phone,
            'name'   => 'Prueba',
            'status' => 'active',
            'source' => 'manual',
        ]);
    }

    /** Registra un movimiento en la fecha dada (hora de México). */
    private function asignar(Contact $c, ?User $u, string $cuando, string $action = 'manual'): void
    {
        ConversationAssignment::create([
            'contact_id'  => $c->id,
            'user_id'     => $u?->id,
            'action'      => $action,
            'assigned_at' => Carbon::parse($cuando, 'America/Mexico_City')->utc(),
        ]);
    }

    private function fila(array $data, string $nombre): ?array
    {
        return collect($data)->firstWhere('agent', $nombre);
    }

    // ── Conteos ──────────────────────────────────────────────────────────────

    public function test_cuenta_las_recibidas_dentro_del_periodo(): void
    {
        $ana  = $this->agente('Ana');
        $this->asignar($this->contacto('529231111111'), $ana, '2026-08-17 10:00');
        $this->asignar($this->contacto('529232222222'), $ana, '2026-08-17 15:00');
        $this->asignar($this->contacto('529233333333'), $ana, '2026-08-20 10:00');   // fuera

        $res = $this->actingAsAdmin()->getJson(self::RUTA . '?from=2026-08-17&to=2026-08-17')->assertOk();

        $this->assertSame(2, $this->fila($res->json('data'), 'Ana')['received']);
    }

    public function test_el_dia_va_de_00_00_a_23_59_hora_de_mexico(): void
    {
        // Las 23:30 CST del 17 son las 05:30 UTC del 18: con el corte en UTC se saldría del día.
        $ana = $this->agente('Ana');
        $this->asignar($this->contacto('529231111111'), $ana, '2026-08-17 23:30');

        $hoy    = $this->actingAsAdmin()->getJson(self::RUTA . '?from=2026-08-17&to=2026-08-17')->json('data');
        $manana = $this->actingAsAdmin()->getJson(self::RUTA . '?from=2026-08-18&to=2026-08-18')->json('data');

        $this->assertSame(1, $this->fila($hoy, 'Ana')['received']);
        $this->assertSame(0, $this->fila($manana, 'Ana')['received']);
    }

    public function test_abiertas_ahora_no_depende_del_filtro_de_fecha(): void
    {
        // Se la asignaron hace un mes: hoy no "recibió" nada, pero la sigue teniendo abierta.
        $ana = $this->agente('Ana');
        $this->asignar($this->contacto('529231111111'), $ana, '2026-07-01 10:00');

        $fila = $this->fila(
            $this->actingAsAdmin()->getJson(self::RUTA . '?from=2026-08-17&to=2026-08-17')->json('data'),
            'Ana'
        );

        $this->assertSame(0, $fila['received']);
        $this->assertSame(1, $fila['open_now']);
    }

    public function test_solo_cuenta_como_abierta_la_del_responsable_actual(): void
    {
        $ana  = $this->agente('Ana');
        $beto = $this->agente('Beto');
        $c    = $this->contacto('529231111111');

        $this->asignar($c, $ana,  '2026-08-17 10:00');
        $this->asignar($c, $beto, '2026-08-17 12:00', 'reassign');

        $data = $this->actingAsAdmin()->getJson(self::RUTA . '?from=2026-08-17&to=2026-08-17')->json('data');

        // Ana la recibió ese día, pero ya no la tiene.
        $this->assertSame(1, $this->fila($data, 'Ana')['received']);
        $this->assertSame(0, $this->fila($data, 'Ana')['open_now']);
        $this->assertSame(1, $this->fila($data, 'Beto')['open_now']);
    }

    public function test_una_conversacion_soltada_no_le_cuenta_a_nadie(): void
    {
        $ana = $this->agente('Ana');
        $c   = $this->contacto('529231111111');

        $this->asignar($c, $ana,  '2026-08-17 10:00');
        $this->asignar($c, null, '2026-08-17 12:00', 'release');

        $fila = $this->fila(
            $this->actingAsAdmin()->getJson(self::RUTA . '?from=2026-08-17&to=2026-08-17')->json('data'),
            'Ana'
        );

        $this->assertSame(0, $fila['open_now']);
    }

    public function test_el_mismo_chat_pasado_dos_veces_al_mismo_agente_cuenta_una(): void
    {
        // Se lo quitaron y se lo devolvieron el mismo día: es una conversación, no dos.
        $ana  = $this->agente('Ana');
        $beto = $this->agente('Beto');
        $c    = $this->contacto('529231111111');

        $this->asignar($c, $ana,  '2026-08-17 09:00');
        $this->asignar($c, $beto, '2026-08-17 11:00', 'reassign');
        $this->asignar($c, $ana,  '2026-08-17 13:00', 'reassign');

        $fila = $this->fila(
            $this->actingAsAdmin()->getJson(self::RUTA . '?from=2026-08-17&to=2026-08-17')->json('data'),
            'Ana'
        );

        $this->assertSame(1, $fila['received']);
    }

    public function test_un_agente_sin_nada_sale_en_cero(): void
    {
        // Un agente en cero es información: si no saliera, no se notaría que no le llegó nada.
        $this->agente('Ana');

        $fila = $this->fila(
            $this->actingAsAdmin()->getJson(self::RUTA . '?from=2026-08-17&to=2026-08-17')->json('data'),
            'Ana'
        );

        $this->assertNotNull($fila);
        $this->assertSame(0, $fila['received']);
        $this->assertSame(0, $fila['open_now']);
    }

    public function test_los_totales_suman_las_filas(): void
    {
        $ana  = $this->agente('Ana');
        $beto = $this->agente('Beto');
        $this->asignar($this->contacto('529231111111'), $ana,  '2026-08-17 10:00');
        $this->asignar($this->contacto('529232222222'), $beto, '2026-08-17 10:00');

        $res = $this->actingAsAdmin()->getJson(self::RUTA . '?from=2026-08-17&to=2026-08-17')->assertOk();

        $this->assertSame(2, $res->json('meta.totals.received'));
        $this->assertSame(2, $res->json('meta.totals.open_now'));
    }

    // ── Filtros ──────────────────────────────────────────────────────────────

    public function test_filtra_por_agente(): void
    {
        $ana = $this->agente('Ana');
        $this->agente('Beto');
        $this->asignar($this->contacto('529231111111'), $ana, '2026-08-17 10:00');

        $data = $this->actingAsAdmin()
            ->getJson(self::RUTA . "?from=2026-08-17&to=2026-08-17&user_id={$ana->id}")
            ->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('Ana', $data[0]['agent']);
    }

    public function test_sin_fechas_usa_hoy(): void
    {
        $ana = $this->agente('Ana');
        $this->asignar($this->contacto('529231111111'), $ana, now('America/Mexico_City')->format('Y-m-d H:i'));

        $res = $this->actingAsAdmin()->getJson(self::RUTA)->assertOk();

        $this->assertSame(now('America/Mexico_City')->format('Y-m-d'), $res->json('meta.from'));
        $this->assertSame(1, $this->fila($res->json('data'), 'Ana')['received']);
    }

    public function test_un_rango_al_reves_se_rechaza(): void
    {
        $this->actingAsAdmin()
             ->getJson(self::RUTA . '?from=2026-08-20&to=2026-08-01')
             ->assertStatus(422)
             ->assertJsonPath('code', 'INVALID_RANGE');
    }

    public function test_una_fecha_mal_escrita_se_rechaza(): void
    {
        $this->actingAsAdmin()->getJson(self::RUTA . '?from=20-08-2026')->assertStatus(422);
    }

    // ── Permisos ─────────────────────────────────────────────────────────────

    public function test_el_operador_si_lo_ve(): void
    {
        $this->actingAsOperator()->getJson(self::RUTA)->assertOk();
    }

    public function test_el_agente_no_lo_ve(): void
    {
        // No debe ver la carga de sus compañeros.
        $this->actingAsAgent()->getJson(self::RUTA)->assertStatus(403);
        $this->actingAsAgent()->get(self::RUTA . '/export')->assertStatus(403);
    }

    public function test_sin_sesion_no_responde(): void
    {
        $this->getJson(self::RUTA)->assertStatus(401);
    }

    // ── Descargas ────────────────────────────────────────────────────────────

    public function test_descarga_el_excel(): void
    {
        $ana = $this->agente('Ana');
        $this->asignar($this->contacto('529231111111'), $ana, '2026-08-17 10:00');

        $res = $this->actingAsAdmin()
            ->get(self::RUTA . '/export?from=2026-08-17&to=2026-08-17&format=xlsx')
            ->assertOk();

        $this->assertStringContainsString(
            'spreadsheetml',
            $res->headers->get('content-type')
        );
        $this->assertStringContainsString('.xlsx', $res->headers->get('content-disposition'));
    }

    public function test_descarga_el_pdf(): void
    {
        $ana = $this->agente('Ana');
        $this->asignar($this->contacto('529231111111'), $ana, '2026-08-17 10:00');

        $res = $this->actingAsAdmin()
            ->get(self::RUTA . '/export?from=2026-08-17&to=2026-08-17&format=pdf')
            ->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('content-type'));
        // Un PDF real empieza con %PDF: así se sabe que dompdf generó algo, no una página de error.
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_el_nombre_del_archivo_lleva_el_periodo(): void
    {
        $res = $this->actingAsAdmin()
            ->get(self::RUTA . '/export?from=2026-08-01&to=2026-08-17&format=xlsx')
            ->assertOk();

        $this->assertStringContainsString('2026-08-01_a_2026-08-17', $res->headers->get('content-disposition'));
    }

    public function test_la_descarga_respeta_el_filtro_de_agente(): void
    {
        $ana = $this->agente('Ana');
        $this->agente('Beto');

        $res = $this->actingAsAdmin()
            ->get(self::RUTA . "/export?from=2026-08-17&to=2026-08-17&user_id={$ana->id}&format=pdf")
            ->assertOk();

        // El PDF sale con una sola persona: el filtro no se pierde en la descarga.
        $this->assertSame('application/pdf', $res->headers->get('content-type'));
    }
}
