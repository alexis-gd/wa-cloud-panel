<?php

namespace Tests\Feature;

use App\Models\MessageLog;
use App\Models\OtpCode;
use App\Services\Otp\OtpCapacity;
use App\Services\WhatsApp\PortfolioLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * La reserva de cupo para el OTP.
 *
 * Meta pone un techo diario por cuenta y **no distingue categorias**: un codigo de verificacion
 * gasta el mismo cupo que un mensaje de publicidad. Por eso el numero dedicado de M6 no le da
 * capacidad propia al OTP, y por eso hay que apartarsela aqui: sin esto, una campana grande se
 * come el cupo del dia y deja a los usuarios de la app del cliente sin poder entrar.
 */
class OtpCapacityTest extends TestCase
{
    use RefreshDatabase;

    private function enviados(int $cuantos): void
    {
        MessageLog::factory()->count($cuantos)->create(['status' => 'sent', 'sent_at' => now()]);
    }

    private function otpEnviados(int $cuantos): void
    {
        for ($i = 0; $i < $cuantos; $i++) {
            OtpCode::create([
                'phone'      => '529231311146',
                'code_hash'  => Hash::make('123456'),
                'status'     => 'sent',
                'sent_at'    => now(),
                'expires_at' => now()->addMinutes(10),
            ]);
        }
    }

    public function test_a_las_campanas_les_toca_el_techo_menos_la_reserva(): void
    {
        config(['otp.reserve_percent' => 10]);
        PortfolioLimit::remember('TIER_250');

        $this->assertSame(250, OtpCapacity::techoReal());
        $this->assertSame(225, OtpCapacity::techoCampanas());
    }

    public function test_sin_reserva_se_comporta_como_antes(): void
    {
        config(['otp.reserve_percent' => 0]);
        PortfolioLimit::remember('TIER_250');

        $this->assertSame(250, OtpCapacity::techoCampanas());
    }

    /** Si Meta todavia no reporta el limite, no se frena nada: no inventamos un techo. */
    public function test_sin_dato_de_meta_no_hay_freno(): void
    {
        $this->assertNull(OtpCapacity::techoReal());
        $this->assertNull(OtpCapacity::techoCampanas());
        $this->assertNull(OtpCapacity::quedanParaOtp());
    }

    /**
     * Las dos tablas suman. Los OTP viven aparte de `message_log` para no envenenar el dedup
     * de campanas, pero para Meta es el mismo cupo: contar solo una tabla hace que el panel
     * crea que le queda espacio que ya no tiene.
     */
    public function test_lo_gastado_suma_campanas_y_otp(): void
    {
        $this->enviados(30);
        $this->otpEnviados(12);

        $this->assertSame(42, OtpCapacity::usadoHoy());
    }

    public function test_lo_que_queda_para_otp_descuenta_todo_lo_del_dia(): void
    {
        config(['otp.reserve_percent' => 10]);
        PortfolioLimit::remember('TIER_250');

        $this->enviados(225);
        $this->otpEnviados(5);

        // Las campanas ya se toparon con su techo (225) pero el OTP sigue teniendo los suyos.
        $this->assertSame(20, OtpCapacity::quedanParaOtp());
    }

    public function test_lo_de_ayer_no_cuenta(): void
    {
        MessageLog::factory()->count(5)->create([
            'status'  => 'sent',
            'sent_at' => now('America/Mexico_City')->subDay()->utc(),
        ]);

        $this->assertSame(0, OtpCapacity::usadoHoy());
    }
}
