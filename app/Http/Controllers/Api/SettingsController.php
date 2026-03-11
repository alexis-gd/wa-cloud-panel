<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SettingsController extends Controller
{
    /**
     * GET /api/settings/token-status
     * Verifica si el token actual en DB es válido contra Meta.
     */
    public function tokenStatus(): JsonResponse
    {
        $phone = PhoneNumber::where('is_active', true)->first();

        if (! $phone) {
            return response()->json(['error' => 'No hay número de teléfono activo'], 404);
        }

        $result = $this->verifyTokenWithMeta($phone->token);

        return response()->json([
            'phone_number_id' => $phone->phone_number_id,
            'display_name'    => $phone->display_name,
            'token_valid'     => $result['valid'],
            'token_user'      => $result['user'] ?? null,
            'token_error'     => $result['error'] ?? null,
        ]);
    }

    /**
     * POST /api/settings/token
     * Actualiza el token del número activo.
     * Body: { "token": "EAAU..." }
     */
    public function updateToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string|min:50',
        ]);

        // Verificar que el token es válido antes de guardar
        $check = $this->verifyTokenWithMeta($data['token']);

        if (! $check['valid']) {
            return response()->json([
                'error'   => 'Token inválido — Meta rechazó la verificación',
                'details' => $check['error'] ?? null,
            ], 422);
        }

        $phone = PhoneNumber::where('is_active', true)->firstOrFail();
        $phone->token = $data['token']; // Eloquent cifra automáticamente con encryptString
        $phone->save();

        return response()->json([
            'message'    => 'Token actualizado correctamente',
            'token_user' => $check['user'],
        ]);
    }

    /**
     * Llama a graph.facebook.com/me para verificar si el token es válido.
     */
    private function verifyTokenWithMeta(string $token): array
    {
        $response = Http::timeout(10)
            ->get('https://graph.facebook.com/v22.0/me', [
                'access_token' => $token,
            ]);

        if ($response->successful() && isset($response->json()['name'])) {
            return ['valid' => true, 'user' => $response->json()['name']];
        }

        $error = $response->json()['error']['message'] ?? 'Error desconocido';
        return ['valid' => false, 'error' => $error];
    }
}
