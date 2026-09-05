<?php

namespace App\Services;

use App\Models\ConversationAssignment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AssignmentService
{
    /**
     * Auto-asigna una conversación al agente óptimo según el modo configurado.
     * Si no hay agentes activos, la conversación queda sin asignar (modo válido).
     */
    public function autoAssign(int $contactId): void
    {
        $agents = User::where('role', 'agent')->where('is_active', true)->get();

        if ($agents->isEmpty()) {
            Log::info("AutoAssign: sin agentes activos — contacto {$contactId} queda sin asignar");
            return;
        }

        $mode  = Setting::get('assignment_mode', 'least_chats');
        $agent = match ($mode) {
            'first_available' => $agents->first(),
            default           => $this->agentWithLeastChats($agents),
        };

        if (! $agent) {
            return;
        }

        ConversationAssignment::create([
            'contact_id'     => $contactId,
            'user_id'        => $agent->id,
            'assigned_by_id' => null,   // lo hizo el sistema, no una persona
            'action'         => ConversationAssignment::ACTION_AUTO,
            'assigned_at'    => now(),
        ]);

        Log::info("AutoAssign: contacto {$contactId} → agente {$agent->id} (modo: {$mode})");
    }

    /**
     * Suelta la conversación: queda "Sin asignar".
     *
     * Antes esto BORRABA las filas del contacto, y con ellas el historial. Ahora agrega una
     * fila de liberación con `user_id = null`: como el responsable actual es siempre la fila
     * más reciente, el contacto queda sin asignar igual, pero se conserva el rastro de quién
     * lo tuvo y quién lo soltó. Se usa cuando el contacto se da de baja - no tiene caso dejar
     * a un agente en un chat al que ya nunca se le podrá escribir.
     */
    public function unassign(int $contactId, ?int $byUserId = null): void
    {
        $actual = ConversationAssignment::where('contact_id', $contactId)
            ->orderByDesc('id')
            ->first();

        // Ya estaba sin asignar: no se apila una liberación tras otra.
        if (! $actual || $actual->user_id === null) {
            return;
        }

        ConversationAssignment::create([
            'contact_id'     => $contactId,
            'user_id'        => null,
            'assigned_by_id' => $byUserId,
            'action'         => ConversationAssignment::ACTION_RELEASE,
            'assigned_at'    => now(),
        ]);

        Log::info("Unassign: contacto {$contactId} liberado (venía del usuario {$actual->user_id})");
    }

    /**
     * Devuelve el agente con menos conversaciones actualmente asignadas.
     * "Actualmente asignado" = el agente es el responsable más reciente del contacto.
     */
    private function agentWithLeastChats(Collection $agents): User
    {
        // Contar cuántas conversaciones tiene cada agente como asignado actual.
        // Un agente es el "actual" cuando su registro es el MAX id para ese contact_id.
        // Solo cuentan las filas más recientes por contacto: una fila de liberación tiene
        // `user_id` null y por lo tanto no le suma carga a nadie.
        $counts = ConversationAssignment::selectRaw('user_id, COUNT(*) as cnt')
            ->whereRaw('id = (SELECT MAX(id) FROM conversation_assignments ca2 WHERE ca2.contact_id = conversation_assignments.contact_id)')
            ->whereIn('user_id', $agents->pluck('id'))
            ->groupBy('user_id')
            ->pluck('cnt', 'user_id');

        return $agents->sortBy(fn (User $a) => $counts->get($a->id, 0))->first();
    }
}
