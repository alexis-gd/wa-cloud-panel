<?php

namespace Tests\Feature;

use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El comando que somete la plantilla de OTP a Meta.
 *
 * Lo que mas se cuida aqui es que NO mande nada sin que se lo pidan: es una accion sobre la
 * cuenta de Meta del cliente, y el default tiene que ser inofensivo.
 */
class CreateOtpTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp.waba_id' => '923331644153132']);
        PhoneNumber::factory()->create(['is_active' => true]);
    }

    // -- No manda nada sin permiso ---------------------------------------------

    public function test_sin_enviar_no_toca_la_api_de_meta(): void
    {
        Http::fake();

        $this->artisan('wa:crear-plantilla-otp')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_con_enviar_si_la_somete(): void
    {
        Http::fake([
            '*/message_templates' => Http::response([
                'id' => '123', 'status' => 'PENDING', 'category' => 'AUTHENTICATION',
            ], 200),
        ]);

        $this->artisan('wa:crear-plantilla-otp', ['--enviar' => true])->assertExitCode(0);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/923331644153132/message_templates'));
    }

    // -- El payload que Meta necesita ------------------------------------------

    public function test_va_como_autenticacion_en_es_mx_con_boton_de_copiar(): void
    {
        Http::fake(['*/message_templates' => Http::response(['id' => '1'], 200)]);

        $this->artisan('wa:crear-plantilla-otp', ['--enviar' => true])->assertExitCode(0);

        Http::assertSent(function ($r) {
            $d = $r->data();

            return $d['category'] === 'AUTHENTICATION'
                && $d['language'] === 'es_MX'
                && $d['components'][2]['buttons'][0]['otp_type'] === 'COPY_CODE';
        });
    }

    /**
     * El BODY de una plantilla de Autenticacion NO lleva `text`: Meta pone el texto y rechaza
     * la peticion si se lo mandamos. Es el error facil de cometer al copiar otra plantilla.
     */
    public function test_el_cuerpo_no_manda_texto_porque_el_texto_es_de_meta(): void
    {
        Http::fake(['*/message_templates' => Http::response(['id' => '1'], 200)]);

        $this->artisan('wa:crear-plantilla-otp', ['--enviar' => true])->assertExitCode(0);

        Http::assertSent(function ($r) {
            $body = $r->data()['components'][0];

            return $body['type'] === 'BODY'
                && ! array_key_exists('text', $body)
                && $body['add_security_recommendation'] === true;
        });
    }

    /**
     * Sin `package_name` y `signature_hash` de la app Android no se puede pedir autocompletado.
     * No los tenemos, asi que el boton tiene que ser COPY_CODE: pedir ONE_TAP sin esos datos
     * seria una plantilla que Meta rechaza o que se cae al boton de copiar de todas formas.
     */
    public function test_no_pide_autocompletado_porque_falta_la_firma_de_la_app(): void
    {
        Http::fake(['*/message_templates' => Http::response(['id' => '1'], 200)]);

        $this->artisan('wa:crear-plantilla-otp', ['--enviar' => true])->assertExitCode(0);

        Http::assertSent(function ($r) {
            $boton = $r->data()['components'][2]['buttons'][0];

            return $boton['otp_type'] !== 'ONE_TAP'
                && ! array_key_exists('supported_apps', $boton);
        });
    }

    public function test_los_minutos_se_pueden_ajustar(): void
    {
        Http::fake(['*/message_templates' => Http::response(['id' => '1'], 200)]);

        $this->artisan('wa:crear-plantilla-otp', ['--minutos' => 5, '--enviar' => true])
             ->assertExitCode(0);

        Http::assertSent(fn ($r) => $r->data()['components'][1]['code_expiration_minutes'] === 5);
    }

    // -- Avisa en vez de reventar ----------------------------------------------

    /** Meta acepta de 1 a 90 minutos; fuera de ahi rechazaria la peticion. */
    public function test_rechaza_minutos_fuera_del_rango_de_meta(): void
    {
        Http::fake();

        $this->artisan('wa:crear-plantilla-otp', ['--minutos' => 120])->assertExitCode(1);
        $this->artisan('wa:crear-plantilla-otp', ['--minutos' => 0])->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_avisa_si_falta_el_waba_id(): void
    {
        config(['services.whatsapp.waba_id' => null]);
        Http::fake();

        $this->artisan('wa:crear-plantilla-otp')->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_avisa_si_meta_la_rechaza(): void
    {
        Http::fake([
            '*/message_templates' => Http::response([
                'error' => ['message' => 'Template name already exists'],
            ], 400),
        ]);

        $this->artisan('wa:crear-plantilla-otp', ['--enviar' => true])->assertExitCode(1);
    }

    public function test_avisa_si_no_hay_numero_con_token(): void
    {
        PhoneNumber::query()->delete();
        Http::fake();

        $this->artisan('wa:crear-plantilla-otp', ['--enviar' => true])->assertExitCode(1);

        Http::assertNothingSent();
    }
}
