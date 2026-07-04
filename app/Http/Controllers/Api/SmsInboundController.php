<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsInboundMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Respuestas SMS entrantes - lista plana de solo lectura (no chat).
 * Muestra fecha · número · mensaje · acción automática (ej. opt-out por STOP).
 */
class SmsInboundController extends Controller
{
    /**
     * GET /api/sms/inbound
     * Lista paginada de SMS entrantes, más recientes primero.
     *
     * Solo respuestas de CONTACTOS (contact_id != null). El chip del gateway recibe
     * todo el SMS entrante del celular (promos/2FA/alertas de operadora tipo UNOTV,
     * TELCEL) que NO son prospectos: esos entrantes vienen de remitentes alfanuméricos
     * que no normalizan a un contacto, así que se guardan en BD (auditoría) pero nunca
     * se muestran aquí. El operador solo ve lo que respondió un contacto real.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SmsInboundMessage::with('contact:id,name')
            ->whereNotNull('contact_id')
            ->orderByDesc('received_at');

        if ($request->filled('q')) {
            $term = $request->input('q');
            $query->where(function ($q) use ($term) {
                $q->where('from_number', 'like', "%{$term}%")
                  ->orWhere('body', 'like', "%{$term}%");
            });
        }

        if ($request->boolean('opt_out_only')) {
            $query->where('action', 'opt_out');
        }

        $page = $query->paginate(30);

        $data = collect($page->items())->map(fn (SmsInboundMessage $m) => [
            'id'           => $m->id,
            'from_number'  => $m->from_number,
            'contact_name' => $m->contact?->name,
            'body'         => $m->body,
            'action'       => $m->action,
            'received_at'  => $m->received_at->setTimezone('America/Mexico_City')->format('Y-m-d H:i'),
        ]);

        return response()->json([
            'status' => 'ok',
            'data'   => $data,
            'meta'   => [
                'total'    => $page->total(),
                'page'     => $page->currentPage(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }
}
