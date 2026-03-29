<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Uso: middleware('role:admin') o middleware('role:admin,operator')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['status' => 'error', 'message' => 'No autenticado.'], 401);
        }

        if (! in_array($user->role, $roles)) {
            return response()->json(['status' => 'error', 'message' => 'Sin permisos para esta acción.'], 403);
        }

        return $next($request);
    }
}
