<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-API-Key');

        // Mismo formato que el resto del API (`convenciones-api.md`): quien lo consume es un
        // sistema externo al que se le documentó `{status, message, code}`. Antes devolvía
        // `{"error":"Unauthorized"}`, otra forma más que su programador tenía que manejar.
        // El mensaje no distingue entre "falta la llave" y "la llave no sirve": decirlo
        // confirmaría a un tercero que una llave existe.
        if (!$key || $key !== config('services.whatsapp.api_key')) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Falta la llave de acceso o no es válida. Mándala en la cabecera X-API-Key.',
                'code'    => 'UNAUTHORIZED',
            ], 401);
        }

        return $next($request);
    }
}
