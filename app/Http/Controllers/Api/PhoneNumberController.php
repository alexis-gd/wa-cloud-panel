<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Services\WhatsApp\PhoneNumberVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alta y gestión de números de WhatsApp (solo superadmin/soporte).
 * Nunca devuelve el token ni los IDs internos de Meta (regla de seguridad #13).
 */
class PhoneNumberController extends Controller
{
    public function index(): JsonResponse
    {
        $numbers = PhoneNumber::orderBy('display_name')
            ->get()
            ->map(fn (PhoneNumber $p) => $this->present($p));

        return response()->json(['status' => 'ok', 'data' => $numbers]);
    }

    public function store(Request $request, PhoneNumberVerifier $verifier): JsonResponse
    {
        // Los IDs de Meta son numéricos (15-16 dígitos típicamente). Validamos formato
        // para atajar errores de dedo antes de gastar una llamada a Meta.
        $data = $request->validate([
            'display_name'    => 'required|string|max:255',
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,20}$/'],
            'waba_id'         => ['required', 'string', 'regex:/^\d{5,20}$/'],
            'token'           => 'nullable|string|min:20',
        ], [
            'phone_number_id.regex' => 'El Phone number ID debe ser solo números (el ID que da Meta).',
            'waba_id.regex'         => 'El WABA ID debe ser solo números (el ID que da Meta).',
            'token.min'             => 'El token parece demasiado corto.',
        ]);

        if (PhoneNumber::where('phone_number_id', $data['phone_number_id'])->exists()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Ese número ya está registrado en el sistema.',
                'code'    => 'DUPLICATE',
            ], 422);
        }

        // El token es a nivel cuenta (System User Token): no se pide en el alta. Se reutiliza
        // el de otro número de la misma WABA; si no hay, el del número activo (el que gestiona
        // el bloque "Token de acceso WhatsApp"). Usar el modelo (no ->value) para que el cast
        // 'encrypted' lo descifre. Se acepta un token explícito por API pero la UI no lo manda.
        $token = $data['token']
            ?? PhoneNumber::where('waba_id', $data['waba_id'])->first()?->token
            ?? PhoneNumber::where('is_active', true)->first()?->token;
        if (! $token) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Configura primero el token de la cuenta en "Token de acceso WhatsApp".',
                'code'    => 'TOKEN_REQUIRED',
            ], 422);
        }

        // El sistema valida el número contra Meta ANTES de guardarlo: si el token o el
        // phone_number_id no sirven, no se crea la fila (nada de números fantasma).
        $verify = $verifier->verify($data['phone_number_id'], $token);
        if (! $verify['ok']) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Meta no reconoció el número o el token: ' . $verify['error'],
                'code'    => 'META_VERIFY_FAILED',
            ], 422);
        }

        // El límite diario real lo dicta Meta (por portfolio). Aquí solo guardamos un tope
        // de warm-up conservador; no se lo pedimos al usuario.
        $phone = PhoneNumber::create([
            'display_name'    => $data['display_name'],
            'phone_number_id' => $data['phone_number_id'],
            'waba_id'         => $data['waba_id'],
            'token'           => $token,
            'daily_limit'     => 250,
            'is_active'       => true,
        ]);

        return response()->json(['status' => 'ok', 'data' => $this->present($phone, $verify['data'])], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $phone = PhoneNumber::find($id);
        if (! $phone) {
            return response()->json(['status' => 'error', 'message' => 'Número no encontrado'], 404);
        }

        $data = $request->validate([
            'display_name' => 'sometimes|string|max:255',
            'daily_limit'  => 'sometimes|integer|min:1|max:1000000',
            'is_active'    => 'sometimes|boolean',
        ]);

        $phone->update($data);

        return response()->json(['status' => 'ok', 'data' => $this->present($phone)]);
    }

    /** Representación segura: sin token ni IDs internos de Meta. */
    private function present(PhoneNumber $p, array $meta = []): array
    {
        return [
            'id'                   => $p->id,
            'display_name'         => $p->display_name,
            'display_phone_number' => $meta['display_phone_number'] ?? null,
            'quality_rating'       => $meta['quality_rating'] ?? null,
            'daily_limit'          => $p->daily_limit,
            'is_active'            => $p->is_active,
            'is_paused'            => $p->isPaused(),
            'paused_until'         => $p->paused_until?->setTimezone('America/Mexico_City')->format('Y-m-d H:i'),
        ];
    }
}
