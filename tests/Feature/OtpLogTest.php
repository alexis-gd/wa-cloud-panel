<?php

namespace Tests\Feature;

use App\Models\OtpCode;
use App\Services\WhatsApp\PortfolioLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * La tabla de codigos del panel.
 *
 * Es de lectura y de admin para arriba: un operador no tiene por que ver los intentos de
 * acceso a la app del cliente, y un agente menos.
 */
class OtpLogTest extends TestCase
{
    use RefreshDatabase;

    private function codigo(array $extra = []): OtpCode
    {
        return OtpCode::create(array_merge([
            'phone'      => '529231311146',
            'code_hash'  => Hash::make('123456'),
            'status'     => 'sent',
            'sent_at'    => now(),
            'expires_at' => now()->addMinutes(10),
        ], $extra));
    }

    public function test_el_admin_ve_la_lista(): void
    {
        $this->codigo(['reference' => 'login-1']);
        $this->actingAsAdmin();

        $this->getJson('/api/otp/log')
             ->assertStatus(200)
             ->assertJsonPath('status', 'ok')
             ->assertJsonPath('data.0.reference', 'login-1')
             ->assertJsonPath('meta.total', 1);
    }

    /**
     * Ni el hash ni nada parecido al codigo pueden salir de aqui.
     *
     * Si el panel mostrara el codigo, el OTP dejaria de probar que la persona tiene el telefono:
     * bastaria con tener el panel abierto para entrar como cualquiera.
     */
    public function test_el_codigo_no_sale_en_la_lista(): void
    {
        $otp = $this->codigo();
        $this->actingAsAdmin();

        $res = $this->getJson('/api/otp/log')->assertStatus(200);

        $this->assertStringNotContainsString($otp->code_hash, json_encode($res->json()));
        $this->assertArrayNotHasKey('code_hash', $res->json('data.0'));
    }

    public function test_filtra_por_estado(): void
    {
        $this->codigo(['status' => 'verified']);
        $this->codigo(['status' => 'failed']);
        $this->actingAsAdmin();

        $res = $this->getJson('/api/otp/log?status=failed')->assertStatus(200);

        $this->assertCount(1, $res->json('data'));
        $this->assertSame('failed', $res->json('data.0.status'));
    }

    public function test_busca_por_numero_y_referencia(): void
    {
        $this->codigo(['reference' => 'alta-998']);
        $this->codigo(['phone' => '525512345678']);
        $this->actingAsAdmin();

        $this->getJson('/api/otp/log?q=alta-998')
             ->assertStatus(200)
             ->assertJsonPath('meta.total', 1);

        $this->getJson('/api/otp/log?q=5512345678')
             ->assertStatus(200)
             ->assertJsonPath('meta.total', 1);
    }

    /**
     * El cupo va en el resumen porque es la pregunta que sigue cuando los codigos no salen:
     * lo primero que hay que descartar es que las campanas se hayan comido el dia.
     */
    public function test_el_resumen_trae_el_cupo_que_queda(): void
    {
        PortfolioLimit::remember('TIER_250');
        $this->codigo();
        $this->actingAsAdmin();

        $res = $this->getJson('/api/otp/log')->assertStatus(200);

        $this->assertSame(1, $res->json('meta.resumen.pedidos_hoy'));
        $this->assertSame(249, $res->json('meta.resumen.cupo_restante'));
    }

    public function test_un_operador_no_entra(): void
    {
        $this->actingAsOperator();

        $this->getJson('/api/otp/log')->assertStatus(403);
    }

    public function test_sin_sesion_no_entra(): void
    {
        $this->getJson('/api/otp/log')->assertStatus(401);
    }
}
