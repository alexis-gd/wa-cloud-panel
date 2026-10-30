<?php

namespace Tests\Feature;

use App\Jobs\SendOtpMessage;
use App\Models\OtpCode;
use App\Models\PhoneNumber;
use App\Services\Otp\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * El API de codigos de verificacion que consume la app del cliente.
 *
 * Como `/api/contacted`, se autentica con `X-API-Key` y no con Sanctum: lo llama un servidor,
 * y un servidor no hace login.
 */
class OtpApiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY        = 'llave-de-prueba';
    private const TELEFONO   = '9231311146';
    private const NORMALIZADO = '529231311146';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp.api_key' => self::KEY]);
        PhoneNumber::factory()->create(['is_active' => true]);
        Queue::fake();
    }

    private function pedir(array $cuerpo = [])
    {
        return $this->postJson('/api/otp/send',
            array_merge(['phone' => self::TELEFONO], $cuerpo),
            ['X-API-Key' => self::KEY]);
    }

    private function verificar(array $cuerpo)
    {
        return $this->postJson('/api/otp/verify',
            array_merge(['phone' => self::TELEFONO], $cuerpo),
            ['X-API-Key' => self::KEY]);
    }

    /**
     * Deja un codigo listo para verificar, sin pasar por el envio.
     *
     * `created_at` se fuerza aparte porque no es `fillable`: Eloquent lo pisa con la hora
     * actual, y entonces el freno de "ya pediste uno hace poco" se dispara en pruebas que no
     * van de eso.
     */
    private function codigoVivo(string $codigo = '123456', array $extra = [], ?int $haceMinutos = null): OtpCode
    {
        $otp = OtpCode::create(array_merge([
            'phone'      => self::NORMALIZADO,
            'code_hash'  => Hash::make($codigo),
            'status'     => 'sent',
            'expires_at' => now()->addMinutes(10),
            'sent_at'    => now(),
        ], $extra));

        if ($haceMinutos !== null) {
            $otp->forceFill(['created_at' => now()->subMinutes($haceMinutos)])->saveQuietly();
        }

        return $otp;
    }

    // -- Pedir el codigo --------------------------------------------------------

    public function test_pide_un_codigo_y_lo_encola(): void
    {
        $this->pedir(['reference' => 'login-8832'])
             ->assertStatus(201)
             ->assertJsonPath('status', 'ok')
             ->assertJsonPath('data.reference', 'login-8832')
             ->assertJsonPath('data.expires_in', 600);

        // El telefono se guarda normalizado, con el 52 que el cliente no tiene que mandar.
        $this->assertDatabaseHas('otp_codes', [
            'phone'     => self::NORMALIZADO,
            'reference' => 'login-8832',
            'status'    => 'pending',
        ]);

        Queue::assertPushed(SendOtpMessage::class);
    }

    /**
     * El codigo no puede salir en la respuesta.
     *
     * Quien llama al API es el servidor del cliente, pero quien debe leer el codigo es el
     * usuario en su WhatsApp. Devolverlo aqui convierte el OTP en un adorno: con la llave del
     * API cualquiera entraria como quien quisiera, sin tocar el telefono de nadie.
     */
    public function test_el_codigo_nunca_viaja_en_la_respuesta(): void
    {
        $res = $this->pedir()->assertStatus(201);

        $this->assertArrayNotHasKey('code', $res->json('data'));
        $this->assertStringNotContainsString(OtpCode::first()->code_hash, json_encode($res->json()));
    }

    public function test_el_codigo_se_guarda_cifrado_no_en_claro(): void
    {
        $this->pedir()->assertStatus(201);

        $guardado = OtpCode::first()->code_hash;

        $this->assertNotEmpty($guardado);
        // Un hash no son seis digitos: si lo fuera, estaria en claro.
        $this->assertDoesNotMatchRegularExpression('/^\d{6}$/', $guardado);
    }

    public function test_rechaza_un_numero_que_no_es_de_mexico(): void
    {
        $this->postJson('/api/otp/send', ['phone' => '123'], ['X-API-Key' => self::KEY])
             ->assertStatus(422)
             ->assertJsonPath('code', 'INVALID_PHONE');

        Queue::assertNothingPushed();
    }

    public function test_no_deja_pedir_dos_codigos_seguidos(): void
    {
        $this->pedir()->assertStatus(201);

        $res = $this->pedir()
                    ->assertStatus(429)
                    ->assertJsonPath('code', 'TOO_SOON');

        $this->assertIsInt($res->json('retry_after'));
    }

    /**
     * La espera sale de la configuracion, no de una constante: es el numero que mas se va a
     * querer mover cuando el cliente vea como se comporta su gente.
     */
    public function test_la_espera_entre_codigos_se_puede_configurar(): void
    {
        config(['otp.resend_seconds' => 180]);

        $this->pedir()->assertStatus(201);

        $res = $this->pedir()->assertStatus(429);

        // Pide justo despues, asi que le faltan casi los 180 completos.
        $this->assertGreaterThan(170, $res->json('retry_after'));
        $this->assertLessThanOrEqual(180, $res->json('retry_after'));
    }

    /** En cero no frena nada: util para pruebas, nunca para produccion. */
    public function test_en_cero_no_hay_espera(): void
    {
        config(['otp.resend_seconds' => 0]);

        $this->pedir()->assertStatus(201);
        $this->pedir()->assertStatus(201);
    }

    public function test_corta_al_numero_que_pide_demasiados_en_un_dia(): void
    {
        for ($i = 0; $i < OtpService::MAX_POR_DIA; $i++) {
            $this->codigoVivo('000000', [
                'status'     => 'expired',
                'expires_at' => now()->subMinute(),
            ], haceMinutos: 5 + $i);
        }

        $this->pedir()
             ->assertStatus(429)
             ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }

    /** Dos codigos vivos a la vez y el usuario no sabe cual escribir. */
    public function test_un_codigo_nuevo_mata_al_anterior(): void
    {
        $viejo = $this->codigoVivo('111111', haceMinutos: 5);

        $this->pedir()->assertStatus(201);

        $this->assertSame('expired', $viejo->fresh()->status);
    }

    // -- Verificar --------------------------------------------------------------

    public function test_verifica_el_codigo_correcto(): void
    {
        $otp = $this->codigoVivo('123456');

        $this->verificar(['code' => '123456'])
             ->assertStatus(200)
             ->assertJsonPath('data.verified', true);

        $otp->refresh();
        $this->assertSame('verified', $otp->status);
        $this->assertNotNull($otp->verified_at);
    }

    public function test_un_codigo_equivocado_no_pasa_y_gasta_un_intento(): void
    {
        $otp = $this->codigoVivo('123456');

        $this->verificar(['code' => '999999'])
             ->assertStatus(422)
             ->assertJsonPath('code', 'INVALID_CODE');

        $this->assertSame(1, $otp->fresh()->attempts);
    }

    public function test_un_codigo_vencido_no_pasa(): void
    {
        $this->codigoVivo('123456', ['expires_at' => now()->subMinute()]);

        $this->verificar(['code' => '123456'])
             ->assertStatus(422)
             ->assertJsonPath('code', 'EXPIRED');
    }

    /** Sin tope de intentos, seis digitos se adivinan a fuerza bruta. */
    public function test_quema_el_codigo_tras_demasiados_intentos(): void
    {
        $otp = $this->codigoVivo('123456', ['attempts' => OtpCode::MAX_INTENTOS]);

        $this->verificar(['code' => '123456'])
             ->assertStatus(422)
             ->assertJsonPath('code', 'TOO_MANY_ATTEMPTS');

        $this->assertSame('expired', $otp->fresh()->status);
    }

    public function test_verificar_sin_codigo_pendiente(): void
    {
        $this->verificar(['code' => '123456'])
             ->assertStatus(422)
             ->assertJsonPath('code', 'NOT_FOUND');
    }

    // -- El contrato ------------------------------------------------------------

    public function test_sin_llave_no_entra(): void
    {
        $this->postJson('/api/otp/send', ['phone' => self::TELEFONO])->assertStatus(401);
        $this->postJson('/api/otp/send', ['phone' => self::TELEFONO], ['X-API-Key' => 'otra'])
             ->assertStatus(401);
    }

    /**
     * Un API con dos formatos de error es un bug aunque cada uno funcione. El error de
     * validacion tiene que salir igual que los demas, no con el formato de Laravel.
     */
    public function test_el_error_de_validacion_respeta_el_mismo_contrato(): void
    {
        $res = $this->postJson('/api/otp/verify',
            ['phone' => self::TELEFONO, 'code' => 'abc'],
            ['X-API-Key' => self::KEY]);

        $res->assertStatus(422)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('code', 'INVALID_PARAMS');

        $this->assertIsString($res->json('message'));
        $res->assertJsonMissingPath('errors');
    }
}
