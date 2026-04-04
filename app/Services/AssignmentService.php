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
            'contact_id'  => $contactId,
            'user_id'     => $agent->id,
            'assigned_at' => now(),
        ]);

        Log::info("AutoAssign: contacto {$contactId} → agente {$agent->id} (modo: {$mode})");
    }

    /**
     * Devuelve el agente con menos conversaciones actualmente asignadas.
     * "Actualmente asignado" = el agente es el responsable más reciente del contacto.
     */
    private function agentWithLeastChats(Collection $agents): User
    {
        // Contar cuántas conversaciones tiene cada agente como asignado actual.
        // Un agente es el "actual" cuando su registro es el MAX id para ese contact_id.
        $counts = ConversationAssignment::selectRaw('user_id, COUNT(*) as cnt')
            ->whereRaw('id = (SELECT MAX(id) FROM conversation_assignments ca2 WHERE ca2.contact_id = conversation_assignments.contact_id)')
            ->whereIn('user_id', $agents->pluck('id'))
            ->groupBy('user_id')
            ->pluck('cnt', 'user_id');

        return $agents->sortBy(fn (User $a) => $counts->get($a->id, 0))->first();
    }
}
