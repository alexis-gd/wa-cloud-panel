<?php

namespace App\Services\Otp;

use App\Jobs\SendOtpMessage;
use App\Models\Contact;
use App\Models\OtpCode;
use Illuminate\Support\Facades\Hash;

/**
 * Genera, manda y verifica los codigos de un solo uso.
 *
 * Quien pide el codigo es el usuario de la app del cliente, no nosotros. Por eso el OTP se
 * salta casi todos los frenos de campana: el dedup, el cooldown, el pospuesto, el horario y la
 * baja de publicidad. Lo unico que lo detiene es el techo de Meta, que no se puede rebasar.
 */
class OtpService
{
    /** Digitos del codigo. Lo decidio el cliente al arrancar el proyecto. */
    public const DIGITOS = 6;

    /** Segundos entre dos peticiones al mismo numero. Frena el boton de "reenviar" nervioso. */
    public const ESPERA_ENTRE_ENVIOS = 60;

    /** Tope por numero en 24h. Contiene un abuso sin estorbarle a nadie normal. */
    public const MAX_POR_DIA = 10;

    /**
     * Pide un codigo para un numero.
     *
     * @return array{ok: bool, otp?: OtpCode, code?: string, message?: string, retry_after?: int}
     */
    public function pedir(string $telefonoCrudo, ?string $referencia = null): array
    {
        $telefono = Contact::normalizePhone($telefonoCrudo);

        if (! $telefono) {
            return ['ok' => false, 'code' => 'INVALID_PHONE',
                    'message' => 'El numero no es valido. Se espera un celular de Mexico a 10 digitos.'];
        }

        if ($this->pidioDemasiado($telefono)) {
            return ['ok' => false, 'code' => 'TOO_MANY_REQUESTS',
                    'message' => 'Este numero pidio demasiados codigos hoy. Intenta manana.'];
        }

        if ($espera = $this->esperaPendiente($telefono)) {
            return ['ok' => false, 'code' => 'TOO_SOON', 'retry_after' => $espera,
                    'message' => "Ya se mando un codigo hace poco. Espera {$espera} segundos."];
        }

        // Un codigo nuevo invalida el anterior: si no, dos codigos vivos a la vez y el usuario
        // no sabe cual escribir.
        $this->caducarVigentes($telefono);

        $codigo = $this->generar();

        $otp = OtpCode::create([
            'phone'      => $telefono,
            'code_hash'  => Hash::make($codigo),
            'reference'  => $referencia,
            'status'     => 'pending',
            // Provisional: el reloj bueno arranca cuando el mensaje sale (`marcarEnviado`).
            'expires_at' => now()->addMinutes(OtpCode::MINUTOS_DE_VIDA),
        ]);

        SendOtpMessage::dispatch($otp->id, $codigo);

        return ['ok' => true, 'otp' => $otp];
    }

    /**
     * Verifica un codigo contra el ultimo vigente del numero.
     *
     * @return array{ok: bool, code?: string, message?: string}
     */
    public function verificar(string $telefonoCrudo, string $codigo): array
    {
        $telefono = Contact::normalizePhone($telefonoCrudo);

        if (! $telefono) {
            return ['ok' => false, 'code' => 'INVALID_PHONE',
                    'message' => 'El numero no es valido.'];
        }

        $otp = OtpCode::where('phone', $telefono)
            ->whereIn('status', ['pending', 'sent'])
            ->latest('id')
            ->first();

        if (! $otp) {
            return ['ok' => false, 'code' => 'NOT_FOUND',
                    'message' => 'No hay un codigo pendiente para ese numero.'];
        }

        if ($otp->expires_at->isPast()) {
            $otp->update(['status' => 'expired']);

            return ['ok' => false, 'code' => 'EXPIRED',
                    'message' => 'El codigo ya caduco. Pide uno nuevo.'];
        }

        if ($otp->attempts >= OtpCode::MAX_INTENTOS) {
            $otp->update(['status' => 'expired']);

            return ['ok' => false, 'code' => 'TOO_MANY_ATTEMPTS',
                    'message' => 'Demasiados intentos. Pide un codigo nuevo.'];
        }

        // Cuenta el intento ANTES de comparar: si contara despues, un error del servidor a
        // media verificacion dejaria intentar sin limite.
        $otp->increment('attempts');

        if (! $otp->coincide($codigo)) {
            return ['ok' => false, 'code' => 'INVALID_CODE',
                    'message' => 'El codigo no coincide.'];
        }

        $otp->update(['status' => 'verified', 'verified_at' => now()]);

        return ['ok' => true];
    }

    /** Seis digitos con el generador criptografico, no `rand()`: un OTP adivinable no es un OTP. */
    private function generar(): string
    {
        return str_pad((string) random_int(0, (10 ** self::DIGITOS) - 1), self::DIGITOS, '0', STR_PAD_LEFT);
    }

    private function esperaPendiente(string $telefono): ?int
    {
        $ultimo = OtpCode::where('phone', $telefono)->latest('id')->first();

        if (! $ultimo) {
            return null;
        }

        $faltan = self::ESPERA_ENTRE_ENVIOS - $ultimo->created_at->diffInSeconds(now());

        return $faltan > 0 ? (int) ceil($faltan) : null;
    }

    private function pidioDemasiado(string $telefono): bool
    {
        return OtpCode::where('phone', $telefono)
            ->where('created_at', '>=', now()->subDay())
            ->count() >= self::MAX_POR_DIA;
    }

    private function caducarVigentes(string $telefono): void
    {
        OtpCode::where('phone', $telefono)
            ->whereIn('status', ['pending', 'sent'])
            ->update(['status' => 'expired']);
    }
}
