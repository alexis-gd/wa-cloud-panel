<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OtpRequest;
use App\Models\OtpCode;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;

/**
 * API de codigos de verificacion para la app del cliente.
 *
 * La consume su servidor, no el panel: por eso va con `X-API-Key` y no con Sanctum.
 *
 * **El codigo nunca sale en la respuesta.** Quien pide el codigo es el servidor del cliente,
 * pero quien lo tiene que leer es el usuario en su WhatsApp. Devolverlo aqui convertiria el
 * OTP en un adorno: cualquiera con la llave del API podria entrar como quien quisiera.
 */
class OtpController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    /**
     * POST /api/otp/send
     * Body: { "phone": "9231311146", "reference": "login-8832", "code": "483920" }
     *
     * `code` es opcional: si la app lo manda, ese es el que recibe el usuario; si no, lo
     * generamos nosotros. En los dos casos se guarda hasheado y no vuelve en la respuesta.
     */
    public function send(OtpRequest $request): JsonResponse
    {
        $res = $this->otp->pedir(
            $request->input('phone'),
            $request->input('reference'),
            $request->input('code'),
        );

        if (! $res['ok']) {
            return $this->error($res['message'], $res['code'], $res['retry_after'] ?? null);
        }

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'id'         => $res['otp']->id,
                'reference'  => $res['otp']->reference,
                // Lo que el usuario va a leer en su WhatsApp. Que coincida con lo que la app
                // le muestre en pantalla es responsabilidad de quien consume este API.
                'expires_in' => OtpCode::MINUTOS_DE_VIDA * 60,
            ],
        ], 201);
    }

    /**
     * POST /api/otp/verify
     * Body: { "phone": "9231311146", "code": "123456" }
     */
    public function verify(OtpRequest $request): JsonResponse
    {
        $res = $this->otp->verificar($request->input('phone'), $request->input('code'));

        if (! $res['ok']) {
            return $this->error($res['message'], $res['code']);
        }

        return response()->json(['status' => 'ok', 'data' => ['verified' => true]]);
    }

    private function error(string $mensaje, string $codigo, ?int $esperar = null): JsonResponse
    {
        $cuerpo = ['status' => 'error', 'message' => $mensaje, 'code' => $codigo];

        if ($esperar !== null) {
            $cuerpo['retry_after'] = $esperar;
        }

        // 429 para los dos frenos de ritmo: es lo que un cliente HTTP ya sabe reintentar.
        $http = in_array($codigo, ['TOO_SOON', 'TOO_MANY_REQUESTS'], true) ? 429 : 422;

        return response()->json($cuerpo, $http);
    }
}
