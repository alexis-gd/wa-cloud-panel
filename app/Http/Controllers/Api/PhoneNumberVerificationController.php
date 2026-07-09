<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Services\WhatsApp\PhoneNumberVerifier;
use Illuminate\Http\JsonResponse;

/**
 * Acción especial: re-verificar un número ya registrado contra Meta.
 * POST /api/phone-numbers/{id}/verify (solo superadmin).
 */
class PhoneNumberVerificationController extends Controller
{
    public function store(int $id, PhoneNumberVerifier $verifier): JsonResponse
    {
        $phone = PhoneNumber::find($id);
        if (! $phone) {
            return response()->json(['status' => 'error', 'message' => 'Número no encontrado'], 404);
        }

        $verify = $verifier->verify($phone->phone_number_id, $phone->token);
        if (! $verify['ok']) {
            return response()->json([
                'status'  => 'error',
                'message' => $verify['error'],
                'code'    => 'META_VERIFY_FAILED',
            ], 422);
        }

        return response()->json(['status' => 'ok', 'data' => $verify['data']]);
    }
}
