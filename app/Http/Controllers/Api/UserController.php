<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    // GET /api/users
    public function index(): JsonResponse
    {
        // Los ocultos no se listan para nadie, ni siquiera para otro superadmin: son cuentas
        // técnicas nuestras y el cliente no tiene por qué administrarlas.
        $users = User::where('hidden', false)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'is_active', 'created_at']);

        return response()->json(['status' => 'ok', 'data' => $users]);
    }

    // POST /api/users
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'password' => ['required', Password::min(8)],
            'role'     => 'required|in:admin,operator,agent',
        ]);

        $user = User::create([
            'name'      => $data['name'],
            'email'     => $data['email'],
            'password'  => Hash::make($data['password']),
            'role'      => $data['role'],
            'is_active' => true,
        ]);

        return response()->json([
            'status' => 'ok',
            'data'   => $user->only(['id', 'name', 'email', 'role', 'is_active', 'created_at']),
        ], 201);
    }

    // PUT /api/users/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Usuario no encontrado.'], 404);
        }

        // No permitir que el admin se desactive o cambie su propio rol
        if ($user->id === $request->user()->id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No puedes modificar tu propio usuario desde aquí.',
            ], 422);
        }

        if ($blindado = $this->siNoPuedeAdministrar($request, $user)) {
            return $blindado;
        }

        $data = $request->validate([
            'role'      => 'sometimes|in:admin,operator,agent',
            'is_active' => 'sometimes|boolean',
            'password'  => ['sometimes', Password::min(8)],
        ]);

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        return response()->json([
            'status' => 'ok',
            'data'   => $user->fresh()->only(['id', 'name', 'email', 'role', 'is_active', 'created_at']),
        ]);
    }

    // DELETE /api/users/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::find($id);

        if (! $user) {
            return response()->json(['status' => 'error', 'message' => 'Usuario no encontrado.'], 404);
        }

        if ($user->id === $request->user()->id) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No puedes eliminar tu propio usuario.',
            ], 422);
        }

        if ($blindado = $this->siNoPuedeAdministrar($request, $user)) {
            return $blindado;
        }

        // Revocar tokens activos antes de eliminar
        $user->tokens()->delete();
        $user->delete();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Devuelve una respuesta de bloqueo si quien pide NO puede administrar esa cuenta, o null
     * si sí puede. Dos reglas:
     *
     * 1. **Nadie administra una cuenta de su mismo nivel o superior.** Antes un `admin` podía
     *    cambiarle la contraseña a un `superadmin` y entrar como él, con acceso al token de Meta.
     * 2. **Las cuentas ocultas no se administran desde la UI.** Se responde 404, no 403: si
     *    dijera "no puedes", estaría confirmando que ese id existe.
     */
    private function siNoPuedeAdministrar(Request $request, User $user): ?JsonResponse
    {
        if ($user->hidden) {
            return response()->json(['status' => 'error', 'message' => 'Usuario no encontrado.'], 404);
        }

        if (! $request->user()->puedeAdministrarA($user)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No puedes administrar una cuenta de tu mismo nivel o superior.',
                'code'    => 'INSUFFICIENT_RANK',
            ], 403);
        }

        return null;
    }
}
