<?php

namespace Tests\Unit;

use App\Models\Setting;
use App\Services\WhatsApp\SendWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Ventana de envío L-V 9:00-22:00 CST. Fuente única del horario para el despacho
 * de campaña y el guardia del job. Todas las fechas ancladas: 2026-07-08 es miércoles.
 */
class SendWindowTest extends TestCase
{
    use RefreshDatabase; // Setting (schedule_bypass) vive en BD

    public function test_abierta_en_dia_habil_dentro_de_horario(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 12:00:00', 'America/Mexico_City')); // miércoles
        $this->assertTrue(SendWindow::isOpen());
    }

    public function test_cerrada_antes_de_las_9(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 08:59:00', 'America/Mexico_City'));
        $this->assertFalse(SendWindow::isOpen());
    }

    public function test_cerrada_justo_a_las_22(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 22:00:00', 'America/Mexico_City'));
        $this->assertFalse(SendWindow::isOpen());
    }

    public function test_cerrada_en_fin_de_semana(): void
    {
        $this->travelTo(Carbon::parse('2026-07-11 12:00:00', 'America/Mexico_City')); // sábado
        $this->assertFalse(SendWindow::isOpen());
    }

    public function test_modo_demo_abre_fuera_de_horario(): void
    {
        Setting::set('schedule_bypass', '1');
        $this->travelTo(Carbon::parse('2026-07-12 03:00:00', 'America/Mexico_City')); // domingo 3AM
        $this->assertTrue(SendWindow::isOpen());
    }

    public function test_next_opening_salta_fin_de_semana(): void
    {
        // Sábado 12:00 -> próxima apertura lunes 9:00
        $this->travelTo(Carbon::parse('2026-07-11 12:00:00', 'America/Mexico_City'));
        $next = SendWindow::nextOpening();
        $this->assertSame('2026-07-13 09:00', $next->format('Y-m-d H:i')); // lunes
        $this->assertSame(1, (int) $next->format('N'));
    }

    public function test_next_opening_mismo_dia_si_antes_de_las_9(): void
    {
        $this->travelTo(Carbon::parse('2026-07-08 07:00:00', 'America/Mexico_City'));
        $this->assertSame('2026-07-08 09:00', SendWindow::nextOpening()->format('Y-m-d H:i'));
    }

    public function test_next_opening_dia_siguiente_si_ya_paso_la_hora(): void
    {
        // Miércoles 23:00 -> jueves 9:00
        $this->travelTo(Carbon::parse('2026-07-08 23:00:00', 'America/Mexico_City'));
        $this->assertSame('2026-07-09 09:00', SendWindow::nextOpening()->format('Y-m-d H:i'));
    }
}
