<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // POST /api/auth/login
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Credenciales incorrectas.',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Usuario desactivado. Contacta al administrador.',
            ], 403);
        }

        // Revocar tokens anteriores y crear uno nuevo
        $user->tokens()->delete();
        $token = $user->createToken('panel')->plainTextToken;

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'token' => $token,
                'user'  => [
                    'id'   => $user->id,
                    'name' => $user->name,
                    'email'=> $user->email,
                    'role' => $user->role,
                ],
            ],
        ]);
    }

    // POST /api/auth/logout
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['status' => 'ok']);
    }

    // GET /api/auth/me
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'id'   => $user->id,
                'name' => $user->name,
                'email'=> $user->email,
                'role' => $user->role,
            ],
        ]);
    }
}
