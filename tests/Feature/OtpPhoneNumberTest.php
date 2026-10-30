<?php

namespace Tests\Feature;

use App\Jobs\SendOtpMessage;
use App\Models\OtpCode;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Elegir por cual numero salen los codigos (partida O3).
 *
 * El job ya sabia leer el Setting desde que se construyo el API, pero no habia forma de
 * ponerlo sin `tinker`. Esto es la pantalla, que es lo que faltaba de la partida.
 *
 * **Separa reputacion, no capacidad.** El limite diario de Meta es por cuenta y lo comparten
 * todos los numeros; lo que se gana es que si una campana quema el numero de publicidad, el de
 * los codigos sigue limpio.
 */
class OtpPhoneNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_superadmin_ve_las_opciones(): void
    {
        $uno = PhoneNumber::factory()->create(['is_active' => true, 'display_name' => 'Campañas 1']);
        PhoneNumber::factory()->create(['is_active' => false, 'display_name' => 'Apagado']);

        $this->actingAsSuperadmin();

        $res = $this->getJson('/api/settings/otp-phone-number')->assertStatus(200);

        $this->assertNull($res->json('data.otp_phone_number_id'));
        // Solo los activos: ofrecer uno apagado seria ofrecer algo que no va a funcionar.
        $this->assertCount(1, $res->json('data.options'));
        $this->assertSame($uno->id, $res->json('data.options.0.id'));
        $this->assertSame('Campañas 1', $res->json('data.options.0.label'));
    }

    public function test_guarda_el_numero_elegido(): void
    {
        $numero = PhoneNumber::factory()->create(['is_active' => true]);
        $this->actingAsSuperadmin();

        $this->putJson('/api/settings/otp-phone-number', ['otp_phone_number_id' => $numero->id])
             ->assertStatus(200)
             ->assertJsonPath('data.otp_phone_number_id', $numero->id);

        $this->assertSame((string) $numero->id, Setting::get('otp_phone_number_id'));
    }

    public function test_dejarlo_vacio_vuelve_a_automatico(): void
    {
        $numero = PhoneNumber::factory()->create(['is_active' => true]);
        Setting::set('otp_phone_number_id', (string) $numero->id);

        $this->actingAsSuperadmin();

        $this->putJson('/api/settings/otp-phone-number', ['otp_phone_number_id' => null])
             ->assertStatus(200);

        $this->assertSame('', Setting::get('otp_phone_number_id'));
    }

    /**
     * Un numero apagado aceptaria la eleccion y luego no mandaria nada: el job caeria al
     * general y nadie entenderia por que los codigos no salen por donde dice la pantalla.
     */
    public function test_no_deja_elegir_un_numero_apagado(): void
    {
        $apagado = PhoneNumber::factory()->create(['is_active' => false]);
        $this->actingAsSuperadmin();

        $this->putJson('/api/settings/otp-phone-number', ['otp_phone_number_id' => $apagado->id])
             ->assertStatus(422)
             ->assertJsonPath('code', 'PHONE_NOT_USABLE');

        $this->assertEmpty(Setting::get('otp_phone_number_id'));
    }

    public function test_un_numero_que_no_existe_se_rechaza(): void
    {
        $this->actingAsSuperadmin();

        $this->putJson('/api/settings/otp-phone-number', ['otp_phone_number_id' => 9999])
             ->assertStatus(422);
    }

    /** Configuracion es solo del superadmin, como el resto de la pantalla. */
    public function test_un_admin_no_entra(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/settings/otp-phone-number')->assertStatus(403);
        $this->putJson('/api/settings/otp-phone-number', ['otp_phone_number_id' => null])->assertStatus(403);
    }

    /** El Phone ID de Meta no se expone al frontend: el operador elige por nombre. */
    public function test_no_expone_identificadores_de_meta(): void
    {
        PhoneNumber::factory()->create(['is_active' => true, 'phone_number_id' => '1082360764952377']);
        $this->actingAsSuperadmin();

        $res = $this->getJson('/api/settings/otp-phone-number')->assertStatus(200);

        $this->assertStringNotContainsString('1082360764952377', json_encode($res->json()));
    }

    /** Lo que se elige en la pantalla es lo que de verdad usa el envio. */
    public function test_lo_elegido_es_por_donde_sale_el_codigo(): void
    {
        PhoneNumber::factory()->create(['is_active' => true, 'phone_number_id' => '111']);
        $elegido = PhoneNumber::factory()->create(['is_active' => true, 'phone_number_id' => '222']);

        Setting::set('otp_phone_number_id', (string) $elegido->id);

        Http::fake(['*/messages' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $otp = OtpCode::create([
            'phone'      => '529231311146',
            'code_hash'  => Hash::make('123456'),
            'status'     => 'pending',
            'expires_at' => now()->addMinutes(10),
        ]);

        (new SendOtpMessage($otp->id, '123456'))->handle(app(WhatsAppClient::class));

        Http::assertSent(fn ($r) => str_contains($r->url(), '/222/messages'));
    }
}
