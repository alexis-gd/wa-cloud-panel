<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Contacts\ContactedLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API para un sistema externo del cliente: a quién contactamos en una fecha o rango.
 *
 * No la consume el panel, la consume otro sistema. Por eso NO va con Sanctum sino con
 * `X-API-Key` (`ApiKeyMiddleware`), que es lo que un servidor puede mandar sin login.
 */
class ContactedController extends Controller
{
    /**
     * GET /api/contacted?date=2026-08-17
     * GET /api/contacted?from=2026-08-01&to=2026-08-17&page=1&per_page=1000
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date'     => 'nullable|date_format:Y-m-d',
            'from'     => 'nullable|date_format:Y-m-d|required_with:to',
            'to'       => 'nullable|date_format:Y-m-d|required_with:from',
            'page'     => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1',
        ]);

        // Una sola fecha o un rango, no las dos cosas: si llegan juntas no se sabe cuál quiso.
        if (isset($data['date']) && isset($data['from'])) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Manda una fecha (date) o un rango (from y to), no las dos cosas.',
                'code'    => 'AMBIGUOUS_RANGE',
            ], 422);
        }

        if (! isset($data['date']) && ! isset($data['from'])) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Falta la fecha. Manda date=YYYY-MM-DD, o from y to para un rango.',
                'code'    => 'MISSING_DATE',
            ], 422);
        }

        $desde = $data['date'] ?? $data['from'];
        $hasta = $data['date'] ?? $data['to'];

        // Las fechas viajan como texto 'Y-m-d' hasta el servicio, que las interpreta en hora
        // de México. Comparar así funciona: en ese formato el orden de texto es el de fecha.
        if ($hasta < $desde) {
            return response()->json([
                'status'  => 'error',
                'message' => 'La fecha final (to) es anterior a la inicial (from).',
                'code'    => 'INVALID_RANGE',
            ], 422);
        }

        $page    = max(1, (int) ($data['page'] ?? 1));
        $perPage = ContactedLookup::perPage($data['per_page'] ?? null);

        $result = ContactedLookup::between($desde, $hasta, $page, $perPage);

        return response()->json([
            'status' => 'ok',
            'data'   => $result['data'],
            'meta'   => [
                'from'     => $desde,
                'to'       => $hasta,
                'total'    => $result['total'],
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => (int) max(1, ceil($result['total'] / $perPage)),
            ],
        ]);
    }
}
