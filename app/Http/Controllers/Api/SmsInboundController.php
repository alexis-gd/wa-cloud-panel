<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsInboundMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Respuestas SMS entrantes - AGRUPADAS por contacto (no es un chat en vivo).
 * Cada grupo = un contacto con todas sus respuestas; el panel las expande.
 *
 * Solo respuestas de CONTACTOS (contact_id != null). El chip del gateway recibe todo
 * el SMS entrante del celular (promos/2FA/alertas de operadora tipo UNOTV, TELCEL) que
 * NO son prospectos: esos vienen de remitentes alfanuméricos que no normalizan a un
 * contacto, así que se guardan en BD (auditoría) pero nunca se muestran aquí.
 */
class SmsInboundController extends Controller
{
    private const PER_PAGE = 30;

    /**
     * GET /api/sms/inbound
     * Grupos (1 por contacto) ordenados por su última respuesta, más reciente primero.
     * Filtros: q (número/texto/nombre) y action (interested | opt_out).
     */
    public function index(Request $request): JsonResponse
    {
        // Base: solo respuestas de contactos reales, con el filtro de búsqueda aplicado.
        $base = SmsInboundMessage::whereNotNull('contact_id');

        if ($request->filled('q')) {
            $term = $request->input('q');
            $base->where(function ($q) use ($term) {
                $q->where('from_number', 'like', "%{$term}%")
                  ->orWhere('body', 'like', "%{$term}%")
                  ->orWhereHas('contact', fn ($c) => $c->where('name', 'like', "%{$term}%"));
            });
        }

        // Filtro por acción a nivel de GRUPO: contactos con al menos 1 mensaje de esa acción.
        $actionFilter = $request->input('action');
        if (in_array($actionFilter, ['interested', 'opt_out'], true)) {
            $contactsWithAction = (clone $base)->where('action', $actionFilter)
                ->distinct()
                ->pluck('contact_id');
            $base->whereIn('contact_id', $contactsWithAction);
        }

        // Total de grupos (contactos distintos) para la paginación.
        $totalGroups = (clone $base)->distinct()->count('contact_id');

        // Página de contactos, ordenados por su última respuesta (offset/limit manual:
        // paginate() sobre GROUP BY cuenta mal el total).
        $pageNum = max(1, $request->integer('page', 1));

        $pageContactIds = (clone $base)
            ->selectRaw('contact_id, MAX(received_at) as last_at')
            ->groupBy('contact_id')
            ->orderByDesc('last_at')
            ->offset(($pageNum - 1) * self::PER_PAGE)
            ->limit(self::PER_PAGE)
            ->pluck('contact_id');

        // Todas las respuestas de esos contactos (respetando el filtro q), más reciente primero.
        $byContact = (clone $base)
            ->whereIn('contact_id', $pageContactIds)
            ->with('contact:id,name')
            ->orderByDesc('received_at')
            ->get()
            ->groupBy('contact_id');

        // Mantener el orden de la página (por última respuesta).
        $data = $pageContactIds->map(function ($contactId) use ($byContact) {
            $messages = $byContact->get($contactId);
            if (! $messages) {
                return null;
            }

            $latest  = $messages->first();
            $actions = $messages->pluck('action')->filter()->unique();

            // Resumen del grupo: la baja manda (terminal/legal); si no, interés; si no, nada.
            $summary = $actions->contains('opt_out')
                ? 'opt_out'
                : ($actions->contains('interested') ? 'interested' : null);

            return [
                'contact_id'       => (int) $contactId,
                'contact_name'     => $latest->contact?->name,
                'from_number'      => $latest->from_number,
                'count'            => $messages->count(),
                'last_body'        => $latest->body,
                'last_received_at' => $latest->received_at->setTimezone('America/Mexico_City')->format('Y-m-d H:i'),
                'summary_action'   => $summary,
                'messages'         => $messages->map(fn (SmsInboundMessage $m) => [
                    'id'          => $m->id,
                    'body'        => $m->body,
                    'action'      => $m->action,
                    'received_at' => $m->received_at->setTimezone('America/Mexico_City')->format('Y-m-d H:i'),
                ])->values(),
            ];
        })->filter()->values();

        return response()->json([
            'status' => 'ok',
            'data'   => $data,
            'meta'   => [
                'total'    => $totalGroups,
                'page'     => $pageNum,
                'per_page' => self::PER_PAGE,
            ],
        ]);
    }
}
