<?php

namespace Tests\Feature;

use App\Jobs\SendOtpMessage;
use App\Models\MessageLog;
use App\Models\OtpCode;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Services\WhatsApp\PortfolioLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El envio del codigo por WhatsApp.
 *
 * Lo que se prueba aqui es lo que distingue a un OTP de un mensaje de campana: la forma del
 * payload de una plantilla de Autenticacion, que no reencola para manana cuando se acaba el
 * cupo, y que no ensucia el historial de campanas.
 */
class SendOtpMessageTest extends TestCase
{
    use RefreshDatabase;

    private const TELEFONO = '529231311146';

    private PhoneNumber $numero;

    protected function setUp(): void
    {
        parent::setUp();

        $this->numero = PhoneNumber::factory()->create(['is_active' => true]);
    }

    /**
     * Mete a Meta contestando que si.
     *
     * No va en `setUp` a proposito: Laravel acumula los stubs de `Http::fake` y gana el primero
     * que empata, asi que un fake puesto arriba deja sin efecto el que un test ponga despues -
     * y el test de error pasaba en verde probando el camino feliz.
     */
    private function exito(): void
    {
        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.OTP1']]], 200)]);
    }

    private function otp(array $extra = []): OtpCode
    {
        return OtpCode::create(array_merge([
            'phone'      => self::TELEFONO,
            'code_hash'  => Hash::make('123456'),
            'status'     => 'pending',
            'expires_at' => now()->addMinutes(10),
        ], $extra));
    }

    private function correr(OtpCode $otp, string $codigo = '123456'): void
    {
        (new SendOtpMessage($otp->id, $codigo))->handle(app(\App\Services\WhatsApp\WhatsAppClient::class));
    }

    // -- La forma del payload ---------------------------------------------------

    /**
     * Meta lo dice textual: "this value must appear twice in the payload". Una sola vez entrega
     * el mensaje con el boton de copiar vacio - el usuario ve el codigo pero no lo puede copiar.
     */
    public function test_el_codigo_va_dos_veces_en_el_payload(): void
    {
        $this->exito();

        $this->correr($this->otp());

        Http::assertSent(function ($r) {
            $c = data_get($r->data(), 'template.components');

            return data_get($c, '0.type') === 'body'
                && data_get($c, '0.parameters.0.text') === '123456'
                && data_get($c, '1.parameters.0.text') === '123456';
        });
    }

    /**
     * El boton sale como `url` aunque la plantilla se creo con un boton `OTP`: Meta lo convierte
     * al aprobarla. Mandarlo como `copy_code` - que es lo que se copia de una plantilla de
     * marketing con cupon - hace que Meta rechace el envio.
     */
    public function test_el_boton_viaja_como_url_no_como_copy_code(): void
    {
        $this->exito();

        $this->correr($this->otp());

        Http::assertSent(function ($r) {
            $boton = data_get($r->data(), 'template.components.1');

            return $boton['type'] === 'button'
                && $boton['sub_type'] === 'url'
                && $boton['index'] === '0';
        });
    }

    // -- Lo que pasa despues ----------------------------------------------------

    public function test_marca_enviado_y_arranca_el_reloj_al_salir(): void
    {
        $this->exito();

        $otp = $this->otp(['expires_at' => now()->addMinutes(10)]);

        $this->travel(3)->minutes();
        $this->correr($otp);

        $otp->refresh();

        $this->assertSame('sent', $otp->status);
        $this->assertSame('wamid.OTP1', $otp->message_id);
        // Los 10 minutos cuentan desde que el mensaje existe, no desde que se genero el codigo:
        // si la cola tardo, el usuario no tiene por que perder ese tiempo.
        $this->assertEqualsWithDelta(600, $otp->expires_at->diffInSeconds(now()), 5);
    }

    /**
     * Un OTP NO se escribe en `message_log`.
     *
     * El dedup y el cooldown de campanas miran esa tabla por numero, sin filtrar por campana:
     * un codigo ahi adentro dejaria al usuario fuera de toda la publicidad por 30 dias, en
     * silencio. Son dos cosas distintas y se guardan distinto.
     */
    public function test_no_ensucia_el_historial_de_campanas(): void
    {
        $this->exito();

        $this->correr($this->otp());

        $this->assertDatabaseCount('message_log', 0);
    }

    // -- El cupo de Meta --------------------------------------------------------

    /**
     * Cuando se acaba el cupo del dia, el OTP falla de inmediato en vez de guardarse para
     * manana como hacen las campanas. Un codigo manana no le sirve a nadie: el usuario esta
     * parado frente a la pantalla de su app esperandolo.
     */
    public function test_sin_cupo_falla_de_inmediato_y_no_reencola(): void
    {
        $this->exito();

        PortfolioLimit::remember('TIER_250');

        MessageLog::factory()->count(250)->create([
            'status'  => 'sent',
            'sent_at' => now(),
        ]);

        $otp = $this->otp();
        $this->correr($otp);

        $otp->refresh();

        $this->assertSame('failed', $otp->status);
        $this->assertSame('NO_CAPACITY', $otp->error_code);
        Http::assertNothingSent();
    }

    public function test_con_cupo_de_sobra_si_envia(): void
    {
        $this->exito();

        PortfolioLimit::remember('TIER_250');

        $otp = $this->otp();
        $this->correr($otp);

        $this->assertSame('sent', $otp->fresh()->status);
    }

    // -- Errores de Meta --------------------------------------------------------

    /** 131026 no se arregla reintentando: el numero no va a aparecer en WhatsApp en 15 segundos. */
    public function test_un_numero_sin_whatsapp_no_se_reintenta(): void
    {
        Http::fake(['*/messages' => Http::response([
            'error' => ['code' => 131026, 'message' => 'Recipient phone number not in allowed list'],
        ], 400)]);

        $otp = $this->otp();
        $this->correr($otp);

        $otp->refresh();

        $this->assertSame('failed', $otp->status);
        $this->assertSame('131026', $otp->error_code);
    }

    // -- Por que numero sale ----------------------------------------------------

    /**
     * Prepara M6 sin bloquearlo: mientras no haya numero dedicado usa el general, y el dia que
     * llegue la SIM del cliente se enchufa con un Setting, sin tocar codigo.
     */
    public function test_usa_el_numero_dedicado_cuando_esta_configurado(): void
    {
        $dedicado = PhoneNumber::factory()->create([
            'is_active'       => true,
            'phone_number_id' => '999888777',
        ]);

        Setting::set('otp_phone_number_id', (string) $dedicado->id);

        $this->exito();

        $this->correr($this->otp());

        Http::assertSent(fn ($r) => str_contains($r->url(), '999888777'));
    }

    public function test_sin_numero_dedicado_sale_por_el_general(): void
    {
        $this->exito();

        $this->correr($this->otp());

        Http::assertSent(fn ($r) => str_contains($r->url(), $this->numero->phone_number_id));
    }
}
