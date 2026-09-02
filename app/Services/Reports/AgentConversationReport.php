<?php

namespace App\Services\Reports;

use App\Models\ConversationAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Reporte de conversaciones por agente.
 *
 * "Cuántas conversaciones tiene un agente" admite dos lecturas que dan números distintos, así
 * que el reporte trae las dos y las nombra:
 *
 * - **Recibidas**: conversaciones que se le asignaron DENTRO del periodo. Es el reparto del
 *   día: sirve para ver cómo se distribuyó la carga y responde al filtro de fecha.
 * - **Abiertas ahora**: las que tiene a su cargo en este momento, sin importar cuándo se le
 *   asignaron. Es la foto de la carga actual y NO depende del filtro de fecha.
 *
 * Un agente puede haber recibido 12 en el día y tener 40 abiertas (arrastra de días previos),
 * o al revés. Con una sola columna el reporte mentiría en la mitad de los casos.
 *
 * El responsable actual de un contacto es siempre su movimiento MÁS RECIENTE
 * (`MAX(id)`), el mismo criterio que usan el listado, el filtro por agente y el reparto
 * automático. Un movimiento de liberación tiene `user_id` null y por eso no le cuenta a nadie.
 */
class AgentConversationReport
{
    private const TZ = 'America/Mexico_City';

    /**
     * @param  string       $desde   Fecha 'Y-m-d' (día CST), inclusive.
     * @param  string       $hasta   Fecha 'Y-m-d' (día CST), inclusive.
     * @param  int|null     $userId  Filtrar a un solo agente.
     * @return array<int, array{user_id:int, agent:string, role:string, received:int, open_now:int}>
     */
    public static function build(string $desde, string $hasta, ?int $userId = null): array
    {
        // Las fechas entran como texto y se interpretan en CST: con objetos Carbon el día se
        // corre al convertir de UTC (ver ContactedLookup, mismo problema).
        $from = Carbon::createFromFormat('Y-m-d', $desde, self::TZ)->startOfDay()->utc();
        $to   = Carbon::createFromFormat('Y-m-d', $hasta, self::TZ)->endOfDay()->utc();

        // Quiénes salen en el reporte: todos los que pueden atender, aunque no tengan nada.
        // Un agente en cero es información, no una fila que sobre.
        $users = User::whereIn('role', ['admin', 'operator', 'agent'])
            ->when($userId, fn ($q) => $q->where('id', $userId))
            ->orderBy('name')
            ->get(['id', 'name', 'role']);

        if ($users->isEmpty()) {
            return [];
        }

        $ids = $users->pluck('id');

        // Recibidas en el periodo. Cuenta contactos distintos: si a un agente le pasaron el
        // mismo chat dos veces en el día, es una conversación, no dos.
        $received = ConversationAssignment::whereIn('user_id', $ids)
            ->whereBetween('assigned_at', [$from, $to])
            ->selectRaw('user_id, COUNT(DISTINCT contact_id) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        // Abiertas ahora: el agente es el responsable actual del contacto.
        $openNow = ConversationAssignment::whereIn('user_id', $ids)
            ->whereRaw('id = (SELECT MAX(id) FROM conversation_assignments ca2 WHERE ca2.contact_id = conversation_assignments.contact_id)')
            ->selectRaw('user_id, COUNT(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        return $users->map(fn (User $u) => [
            'user_id'  => $u->id,
            'agent'    => $u->name,
            'role'     => $u->role,
            'received' => (int) ($received[$u->id] ?? 0),
            'open_now' => (int) ($openNow[$u->id] ?? 0),
        ])->values()->all();
    }

    /** Totales de la tabla, para el pie del reporte. */
    public static function totals(array $rows): array
    {
        return [
            'received' => array_sum(array_column($rows, 'received')),
            'open_now' => array_sum(array_column($rows, 'open_now')),
        ];
    }
}
