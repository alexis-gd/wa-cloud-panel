<?php

namespace Tests\Feature;

use App\Models\MessageLog;
use App\Models\OtpCode;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Confirmacion de entrega de los codigos (partida O1.5).
 *
 * El webhook de Meta avisa si el mensaje llego, lo leyeron o rebotó. Esos avisos se buscaban
 * solo en `message_log`, y los codigos no viven ahi: se descartaban en silencio, y el panel
 * decia "Enviado" para siempre aunque el telefono del usuario estuviera apagado.
 */
class OtpDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PhoneNumber::factory()->create(['is_active' => true]);
        config(['services.whatsapp.app_secret' => 'test_secret']);
    }

    private function otp(string $wamid = 'wamid.OTP1', array $extra = []): OtpCode
    {
        return OtpCode::create(array_merge([
            'phone'      => '529231311146',
            'code_hash'  => Hash::make('123456'),
            'status'     => 'sent',
            'message_id' => $wamid,
            'sent_at'    => now(),
            'expires_at' => now()->addMinutes(10),
        ], $extra));
    }

    /** Manda el aviso de estado tal como lo manda Meta, firmado. */
    private function avisar(string $wamid, string $estado, array $errores = []): void
    {
        $evento = ['id' => $wamid, 'status' => $estado, 'timestamp' => (string) now()->timestamp];

        if ($errores) {
            $evento['errors'] = [$errores];
        }

        $cuerpo = ['entry' => [['changes' => [['value' => ['statuses' => [$evento]]]]]]];
        $crudo  = json_encode($cuerpo);

        $this->call('POST', '/api/webhook', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $crudo, 'test_secret'),
            'CONTENT_TYPE'             => 'application/json',
        ], $crudo)->assertStatus(200);
    }

    public function test_anota_que_el_codigo_llego(): void
    {
        $otp = $this->otp();

        $this->avisar('wamid.OTP1', 'delivered');

        $otp->refresh();

        $this->assertSame('delivered', $otp->delivery_status);
        $this->assertNotNull($otp->delivered_at);
        // El estado del codigo no se toca: entregado no es lo mismo que usado.
        $this->assertSame('sent', $otp->status);
    }

    public function test_anota_que_lo_leyeron(): void
    {
        $otp = $this->otp();

        $this->avisar('wamid.OTP1', 'read');

        $this->assertSame('read', $otp->fresh()->delivery_status);
    }

    /**
     * Si el mensaje rebotó, el codigo muere con el: nadie lo va a poder escribir nunca. Dejarlo
     * en "Enviado" haria que el operador le dijera a la persona que lo busque en su WhatsApp.
     */
    public function test_un_mensaje_que_rebota_mata_el_codigo(): void
    {
        $otp = $this->otp();

        $this->avisar('wamid.OTP1', 'failed', ['code' => 131026, 'title' => 'Receptor no disponible']);

        $otp->refresh();

        $this->assertSame('failed', $otp->delivery_status);
        $this->assertSame('failed', $otp->status);
        $this->assertSame('131026', $otp->error_code);
        $this->assertSame('Receptor no disponible', $otp->error_message);
    }

    /**
     * El aviso de un codigo no debe tocar el historial de campanas, ni al reves. Se distinguen
     * por el `wamid`, que es unico en Meta.
     */
    public function test_el_aviso_de_un_codigo_no_toca_el_historial_de_campanas(): void
    {
        $this->otp('wamid.SOLO-OTP');

        $this->avisar('wamid.SOLO-OTP', 'delivered');

        $this->assertDatabaseCount('message_log', 0);
    }

    public function test_un_aviso_de_campana_sigue_funcionando_igual(): void
    {
        $log = MessageLog::factory()->create([
            'wa_message_id' => 'wamid.CAMPANA',
            'status'        => 'sent',
            'sent_at'       => now(),
        ]);

        $this->avisar('wamid.CAMPANA', 'delivered');

        $this->assertSame('delivered', $log->fresh()->status);
    }

    public function test_el_panel_muestra_la_entrega(): void
    {
        $this->otp('wamid.OTP1', ['delivery_status' => 'delivered', 'delivered_at' => now()]);
        $this->actingAsAdmin();

        $this->getJson('/api/otp/log')
             ->assertStatus(200)
             ->assertJsonPath('data.0.delivery', 'delivered')
             ->assertJsonPath('meta.resumen.entregados', 1);
    }

    /** Sin aviso de Meta todavia, la columna va vacia - no es lo mismo que "no llego". */
    public function test_sin_aviso_la_entrega_va_vacia(): void
    {
        $this->otp();
        $this->actingAsAdmin();

        $this->getJson('/api/otp/log')
             ->assertStatus(200)
             ->assertJsonPath('data.0.delivery', null)
             ->assertJsonPath('meta.resumen.entregados', 0);
    }
}
