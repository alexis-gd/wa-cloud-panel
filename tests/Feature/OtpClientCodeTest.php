<?php

namespace Tests\Feature;

use App\Jobs\SendOtpMessage;
use App\Models\OtpCode;
use App\Models\PhoneNumber;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * La app del cliente puede mandar su propio codigo.
 *
 * Asi quedo cotizado el servicio ("recibe el numero **y el codigo**, valida y entrega"). Se
 * construyo al reves - generandolo nosotros - y se corrigio soportando las dos formas: si la
 * peticion trae `code`, ese es el que recibe el usuario; si no, lo generamos.
 *
 * Y se aprovecho para cerrar una trampa: antes, mandar `code` a `/send` respondia 201 y el
 * campo se ignoraba en silencio. Quien lo mandaba se quedaba convencido de que habia elegido
 * el codigo mientras al usuario le llegaba otro.
 */
class OtpClientCodeTest extends TestCase
{
    use RefreshDatabase;

    private const KEY      = 'llave-de-prueba';
    private const TELEFONO = '9231311146';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.whatsapp.api_key' => self::KEY]);
        PhoneNumber::factory()->create(['is_active' => true, 'phone_number_id' => '555']);
    }

    private function pedir(array $cuerpo)
    {
        return $this->postJson('/api/otp/send',
            array_merge(['phone' => self::TELEFONO], $cuerpo),
            ['X-API-Key' => self::KEY]);
    }

    // -- El codigo que manda la app ---------------------------------------------

    public function test_acepta_el_codigo_que_manda_la_app(): void
    {
        Queue::fake();

        $this->pedir(['code' => '033192'])
             ->assertStatus(201)
             ->assertJsonPath('status', 'ok');

        $otp = OtpCode::first();

        $this->assertTrue($otp->coincide('033192'));
        $this->assertSame('cliente', $otp->code_source);

        Queue::assertPushed(SendOtpMessage::class);
    }

    /** Ese y no otro: es justo lo que fallaba antes, en silencio. */
    public function test_el_codigo_que_viaja_a_whatsapp_es_el_que_mando_la_app(): void
    {
        Http::fake([
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.X']]], 200),
        ]);

        $this->pedir(['code' => '033192'])->assertStatus(201);

        $otp = OtpCode::first();
        (new SendOtpMessage($otp->id, '033192'))->handle(app(WhatsAppClient::class));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/messages')
            && data_get($r->data(), 'template.components.0.parameters.0.text') === '033192');
    }

    public function test_sin_codigo_lo_generamos_nosotros(): void
    {
        Queue::fake();

        $this->pedir([])->assertStatus(201);

        $this->assertSame('propio', OtpCode::first()->code_source);
    }

    /** La plantilla de Meta espera 6 digitos: cualquier otra cosa llega mal formada. */
    public function test_rechaza_un_codigo_que_no_son_seis_digitos(): void
    {
        Queue::fake();

        foreach (['123', '1234567', 'abcdef', '12 34 56'] as $malo) {
            $this->pedir(['code' => $malo])
                 ->assertStatus(422)
                 ->assertJsonPath('code', 'INVALID_PARAMS');
        }

        Queue::assertNothingPushed();
    }

    /** Se verifica igual, venga de donde venga. */
    public function test_el_codigo_de_la_app_se_verifica_normal(): void
    {
        Queue::fake();

        $this->pedir(['code' => '033192'])->assertStatus(201);

        $this->postJson('/api/otp/verify',
            ['phone' => self::TELEFONO, 'code' => '033192'],
            ['X-API-Key' => self::KEY])
             ->assertStatus(200)
             ->assertJsonPath('data.verified', true);
    }

    /** Tampoco se devuelve, aunque ellos ya lo conozcan: la respuesta no cambia de forma. */
    public function test_tampoco_se_devuelve_el_codigo_propio_de_la_app(): void
    {
        Queue::fake();

        $res = $this->pedir(['code' => '033192'])->assertStatus(201);

        $this->assertArrayNotHasKey('code', $res->json('data'));
    }

    // -- Campos que no reconocemos ----------------------------------------------

    /**
     * Laravel ignora en silencio lo que no valida. En un API eso es una trampa: un `codigo`
     * mal escrito responde 201 como si se hubiera entendido, y quien lo mando sigue creyendo
     * que configuro algo que nunca llego.
     */
    public function test_un_campo_mal_escrito_falla_en_vez_de_ignorarse(): void
    {
        Queue::fake();

        $res = $this->pedir(['codigo' => '033192'])
                    ->assertStatus(422)
                    ->assertJsonPath('code', 'UNKNOWN_PARAMS');

        $this->assertStringContainsString('codigo', $res->json('message'));

        Queue::assertNothingPushed();
    }

    public function test_dice_cuales_campos_si_acepta(): void
    {
        Queue::fake();

        $mensaje = $this->pedir(['inventado' => 'x'])->json('message');

        $this->assertStringContainsString('phone', $mensaje);
        $this->assertStringContainsString('reference', $mensaje);
        $this->assertStringContainsString('code', $mensaje);
    }

    public function test_al_verificar_tambien_rechaza_lo_que_sobra(): void
    {
        $this->postJson('/api/otp/verify',
            ['phone' => self::TELEFONO, 'code' => '123456', 'extra' => 'x'],
            ['X-API-Key' => self::KEY])
             ->assertStatus(422)
             ->assertJsonPath('code', 'UNKNOWN_PARAMS');
    }

    public function test_los_campos_de_siempre_siguen_pasando(): void
    {
        Queue::fake();

        $this->pedir(['reference' => 'login-1', 'code' => '033192'])->assertStatus(201);
    }
}
