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
        $data = $request->validate([
            'display_name'    => 'required|string|max:255',
            'phone_number_id' => 'required|string|max:64',
            'waba_id'         => 'required|string|max:64',
            'token'           => 'required|string',
            'daily_limit'     => 'required|integer|min:1|max:1000000',
        ]);

        if (PhoneNumber::where('phone_number_id', $data['phone_number_id'])->exists()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Ese número ya está registrado en el sistema.',
                'code'    => 'DUPLICATE',
            ], 422);
        }

        // El sistema valida el número contra Meta ANTES de guardarlo: si el token o el
        // phone_number_id no sirven, no se crea la fila (nada de números fantasma).
        $verify = $verifier->verify($data['phone_number_id'], $data['token']);
        if (! $verify['ok']) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Meta no reconoció el número o el token: ' . $verify['error'],
                'code'    => 'META_VERIFY_FAILED',
            ], 422);
        }

        $phone = PhoneNumber::create([
            'display_name'    => $data['display_name'],
            'phone_number_id' => $data['phone_number_id'],
            'waba_id'         => $data['waba_id'],
            'token'           => $data['token'],
            'daily_limit'     => $data['daily_limit'],
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
