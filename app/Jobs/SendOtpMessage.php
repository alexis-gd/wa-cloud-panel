<?php

namespace App\Jobs;

use App\Models\OtpCode;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Services\Otp\OtpCapacity;
use App\Services\Otp\OtpTemplate;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Manda un codigo de verificacion por WhatsApp.
 *
 * **Va en la cola `otp`, no en la de campanas.** Un codigo que vive 10 minutos no puede esperar
 * detras de 10,000 mensajes de publicidad; el worker atiende esta cola primero.
 *
 * **No reencola por falta de cupo.** Las campanas, cuando se topan con el limite de Meta, se
 * guardan para manana. Un codigo manana no le sirve a nadie: el usuario esta parado frente a la
 * pantalla de su app. Si no hay cupo, falla de inmediato y la app del cliente se entera.
 *
 * Tampoco respeta el horario de envio ni la baja de publicidad: el codigo lo pidio el usuario
 * en ese momento, no se lo estamos ofreciendo nosotros.
 */
class SendOtpMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Corto a proposito: el codigo tiene 10 minutos de vida, no se puede esperar un minuto. */
    public int $backoff = 15;

    public function __construct(
        private readonly int    $otpId,
        private readonly string $codigo,
    ) {
        $this->onQueue('otp');
    }

    public function handle(WhatsAppClient $client): void
    {
        $otp = OtpCode::find($this->otpId);

        if (! $otp || $otp->status !== 'pending') {
            return;
        }

        $numero = $this->numero();

        if (! $numero) {
            $otp->marcarFallido('NO_PHONE', 'No hay un numero de WhatsApp activo con token.');
            Log::error('SendOtpMessage: sin numero activo', ['otp_id' => $this->otpId]);

            return;
        }

        $techo = OtpCapacity::techoReal();

        if ($techo !== null && OtpCapacity::usadoHoy() >= $techo) {
            $otp->marcarFallido('NO_CAPACITY', 'La cuenta de WhatsApp llego a su limite diario de Meta.');
            Log::critical('SendOtpMessage: se acabo el cupo del dia, OTP no enviado', [
                'otp_id' => $this->otpId,
                'techo'  => $techo,
            ]);

            return;
        }

        $payload = OtpTemplate::payload(
            $otp->phone,
            (string) config('otp.template'),
            (string) config('otp.language'),
            $this->codigo,
        );

        $res = $client->post($numero->phone_number_id, $numero->token, $payload);

        if ($res['ok']) {
            $otp->marcarEnviado(data_get($res['body'], 'messages.0.id', ''));

            return;
        }

        $codigoError = (string) data_get($res['body'], 'error.code', '');
        $mensaje     = (string) data_get($res['body'], 'error.message', 'Meta rechazo el envio.');

        // 131026 (no tiene WhatsApp) y 132001 (plantilla no aprobada) no se arreglan
        // reintentando: el numero no va a aparecer en WhatsApp en 15 segundos.
        if (in_array($codigoError, ['131026', '132001'], true) || $this->attempts() >= $this->tries) {
            $otp->marcarFallido($codigoError, $mensaje);
            Log::error('SendOtpMessage: no se pudo enviar', [
                'otp_id' => $this->otpId,
                'error'  => $codigoError,
            ]);

            return;
        }

        $this->release($this->backoff);
    }

    /**
     * El numero por el que sale el OTP.
     *
     * Si hay uno dedicado configurado (M6), ese. Si no, el primero activo - asi el API funciona
     * desde el dia uno y el numero dedicado se enchufa despues sin tocar codigo.
     */
    private function numero(): ?PhoneNumber
    {
        $dedicado = Setting::get('otp_phone_number_id');

        if ($dedicado) {
            $numero = PhoneNumber::where('id', $dedicado)->whereNotNull('token')->first();

            if ($numero) {
                return $numero;
            }

            Log::warning('SendOtpMessage: el numero dedicado de OTP no sirve, usando el general', [
                'setting' => $dedicado,
            ]);
        }

        return PhoneNumber::where('is_active', true)->whereNotNull('token')->first();
    }

    public function failed(\Throwable $e): void
    {
        OtpCode::where('id', $this->otpId)
            ->where('status', 'pending')
            ->update(['status' => 'failed', 'error_message' => substr($e->getMessage(), 0, 500)]);
    }
}
