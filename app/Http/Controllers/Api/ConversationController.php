<?php

namespace App\Http\Controllers\Api;

use App\Events\ConversationUpdated;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationAssignment;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\QuickReply;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConversationController extends Controller
{
    // GET /api/conversations — lista de contactos con conversaciones abiertas
    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $authUser */
        $authUser = $request->user();

        $query = Contact::whereHas('conversations')
            ->withExists([
                'conversations as window_open' => fn ($q) => $q
                    ->where('direction', 'inbound')
                    ->where('created_at', '>=', now()->subHours(24)),
            ])
            // `latestConversation` es un `latestOfMany`, no un `limit(1)` sobre el hasMany: con
            // limit, el eager load devolvía UNA fila para toda la consulta y el resto de los
            // contactos quedaba sin último mensaje (sin vista previa y sin fecha para ordenar).
            ->with([
                'latestConversation',
                'assignments' => fn ($q) => $q->latest('assigned_at')->limit(1)->with('user:id,name'),
            ]);

        // Agentes solo ven conversaciones que tienen asignadas
        if ($authUser->role === 'agent') {
            $query->whereHas('assignments', function ($q) use ($authUser) {
                // La asignación más reciente debe ser a este agente
                $q->where('user_id', $authUser->id)
                  ->whereRaw('id = (SELECT MAX(id) FROM conversation_assignments ca2 WHERE ca2.contact_id = conversation_assignments.contact_id)');
            });
        }

        $contacts = $query->get()
            ->map(fn (Contact $c) => [
                'id'              => $c->id,
                'name'            => $c->name,
                'phone'           => $c->phone,
                'status'          => $c->status,
                'snoozed_until'   => $c->snoozed_until,
                'last_message'    => $c->latestConversation?->body,
                'last_message_at' => $c->latestConversation?->created_at,
                'window_open'     => (bool) $c->window_open,
                'assigned_to'     => $c->assignments->first()?->user
                    ? ['id' => $c->assignments->first()->user->id, 'name' => $c->assignments->first()->user->name]
                    : null,
            ])
            ->sortByDesc('last_message_at')
            ->values();

        return response()->json(['status' => 'ok', 'data' => $contacts]);
    }

    /**
     * GET /api/conversations/assignable-users — a quién se le puede pasar una conversación.
     *
     * Existe porque el desplegable de "Asignar a" se llenaba con `GET /users`, que es solo
     * admin: al operador le respondía 403 y el selector salía en "No available options",
     * aunque el backend sí le permite asignar. Devuelve únicamente id y nombre - nada de
     * correos ni roles, que no hacen falta para asignar.
     */
    public function assignableUsers(): JsonResponse
    {
        $users = User::where('is_active', true)
            ->whereIn('role', ['admin', 'operator', 'agent']) // fuera superadmin: es cuenta de soporte
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['status' => 'ok', 'data' => $users]);
    }

    // POST /api/conversations/{contactId}/assign — asignar a un agente (admin/operator)
    public function assign(Request $request, int $contactId): JsonResponse
    {
        $request->validate(['user_id' => 'required|integer|exists:users,id']);

        $contact = Contact::find($contactId);
        if (! $contact) {
            return response()->json(['status' => 'error', 'message' => 'Contacto no encontrado.'], 404);
        }

        $user = User::find($request->user_id);

        ConversationAssignment::create([
            'contact_id'  => $contactId,
            'user_id'     => $request->user_id,
            'assigned_at' => now(),
        ]);

        // Tiempo real: la fila de la lista y el panel derecho cambian solos para todos.
        event(new ConversationUpdated($contactId));

        return response()->json([
            'status' => 'ok',
            'data'   => ['assigned_to' => ['id' => $user->id, 'name' => $user->name]],
        ]);
    }

    // POST /api/conversations/{contactId}/claim — el agente se autoasigna
    public function claim(Request $request, int $contactId): JsonResponse
    {
        $contact = Contact::find($contactId);
        if (! $contact) {
            return response()->json(['status' => 'error', 'message' => 'Contacto no encontrado.'], 404);
        }

        /** @var \App\Models\User $authUser */
        $authUser = $request->user();

        ConversationAssignment::create([
            'contact_id'  => $contactId,
            'user_id'     => $authUser->id,
            'assigned_at' => now(),
        ]);

        // Tiempo real: la fila de la lista y el panel derecho cambian solos para todos.
        event(new ConversationUpdated($contactId));

        return response()->json([
            'status' => 'ok',
            'data'   => ['assigned_to' => ['id' => $authUser->id, 'name' => $authUser->name]],
        ]);
    }

    // GET /api/conversations/{contactId} — historial de chat con un contacto
    public function show(int $contactId): JsonResponse
    {
        $contact = Contact::findOrFail($contactId);

        // Asignación actual (más reciente) para que el panel derecho la muestre y se
        // sincronice en vivo al refetch el chat.
        $currentAssignment = $contact->assignments()
            ->latest('assigned_at')
            ->with('user:id,name')
            ->first()?->user;

        $messages = Conversation::where('contact_id', $contactId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (Conversation $m) => [
                'id'           => $m->id,
                'direction'    => $m->direction,
                'message_type' => $m->message_type,
                'body'         => $m->body,
                'status'       => $m->status,
                'created_at'   => $m->created_at,
            ]);

        $windowOpen = Conversation::where('contact_id', $contactId)
            ->where('direction', 'inbound')
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'contact'     => [
                    'id'            => $contact->id,
                    'name'          => $contact->name,
                    'phone'         => $contact->phone,
                    'status'        => $contact->status,
                    'snoozed_until' => $contact->snoozed_until,
                    'assigned_to'   => $currentAssignment
                        ? ['id' => $currentAssignment->id, 'name' => $currentAssignment->name]
                        : null,
                ],
                'messages'    => $messages,
                'window_open' => $windowOpen,
            ],
        ]);
    }

    // POST /api/conversations/{contactId}/messages — enviar mensaje desde el panel
    public function send(Request $request, int $contactId, WhatsAppClient $client): JsonResponse
    {
        $request->validate(['body' => 'required|string|max:1024']);

        $contact = Contact::findOrFail($contactId);

        if ($contact->status === 'opted_out') {
            return response()->json([
                'status'  => 'error',
                'message' => 'El contacto tiene opt-out activo.',
                'code'    => 'OPTED_OUT',
            ], 422);
        }

        // Bloquear si el último mensaje a este contacto fue un fallo de entrega conocido
        $lastLog = MessageLog::where('to_number', $contact->phone)
            ->whereIn('status', ['failed', 'sent', 'delivered', 'read'])
            ->latest('updated_at')
            ->first();

        if ($lastLog && $lastLog->status === 'failed' && $lastLog->delivery_error_code) {
            // Si el contacto respondió después del fallo, el bloqueo no aplica
            $repliedAfterFailure = Conversation::where('contact_id', $contactId)
                ->where('direction', 'inbound')
                ->where('created_at', '>', $lastLog->updated_at)
                ->exists();

            if (!$repliedAfterFailure) {
                $friendlyErrors = [
                    131049 => 'Meta bloqueó temporalmente las entregas a este número por calidad. Intenta más tarde.',
                    131048 => 'Meta detectó actividad inusual. Los mensajes a este número están pausados.',
                    131026 => 'Este número no tiene WhatsApp activo.',
                    368    => 'La cuenta de WhatsApp fue bloqueada temporalmente por Meta.',
                    470    => 'La plantilla usada no fue aprobada por Meta.',
                ];
                $reason = $friendlyErrors[$lastLog->delivery_error_code]
                    ?? 'El último mensaje a este contacto no fue entregado por Meta.';

                return response()->json([
                    'status'  => 'error',
                    'message' => "No se puede enviar: {$reason}",
                    'code'    => 'DELIVERY_BLOCKED',
                ], 422);
            }
        }

        // Verificar ventana de 24h
        $windowOpen = Conversation::where('contact_id', $contactId)
            ->where('direction', 'inbound')
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();

        if (!$windowOpen) {
            return response()->json([
                'status'  => 'error',
                'message' => 'La ventana de 24h está cerrada. Usa una plantilla para reabrir la conversación.',
                'code'    => 'WINDOW_CLOSED',
            ], 422);
        }

        // Obtener número de envío activo
        $phoneNumber = PhoneNumber::where('is_active', true)->first();

        if (!$phoneNumber) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No hay número de WhatsApp activo configurado.',
                'code'    => 'NO_PHONE_NUMBER',
            ], 500);
        }

        if ($phoneNumber->isPaused()) {
            $retryAt = $phoneNumber->paused_until->setTimezone('America/Mexico_City')->format('g:i A');
            return response()->json([
                'status'  => 'error',
                'message' => "Este número no puede recibir mensajes ahora. Meta bloqueó temporalmente las entregas. Vuelve a intentarlo después de las {$retryAt}.",
                'code'    => 'PHONE_PAUSED',
            ], 422);
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $contact->phone,
            'type'              => 'text',
            'text'              => ['body' => $request->body],
        ];

        $response = $client->post($phoneNumber->phone_number_id, $phoneNumber->token, $payload);

        if (!$response['ok']) {
            Log::error('ConversationController: error enviando mensaje', [
                'contact_id' => $contactId,
                'response'   => $response['body'],
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Error al enviar el mensaje.',
                'code'    => 'SEND_FAILED',
            ], 500);
        }

        $waMessageId = data_get($response, 'body.messages.0.id');

        $conversation = Conversation::create([
            'contact_id'    => $contact->id,
            'direction'     => 'outbound',
            'message_type'  => 'text',
            'body'          => $request->body,
            'wa_message_id' => $waMessageId,
            'status'        => 'sent',
            'window_open'   => true,
        ]);

        // Tiempo real: preview/hora de la fila y el chat abierto de otros operadores.
        event(new ConversationUpdated($contact->id));

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'id'         => $conversation->id,
                'body'       => $conversation->body,
                'direction'  => 'outbound',
                'status'     => 'sent',
                'created_at' => $conversation->created_at,
            ],
        ], 201);
    }

    // GET /api/quick-replies — catálogo de respuestas rápidas
    public function quickReplies(): JsonResponse
    {
        $replies = QuickReply::orderBy('sort_order')->get(['id', 'title', 'body']);

        return response()->json(['status' => 'ok', 'data' => $replies]);
    }

    // POST /api/quick-replies — crear respuesta rápida (solo admin)
    public function storeQuickReply(Request $request): JsonResponse
    {
        $request->validate([
            'title'      => 'required|string|max:100',
            'body'       => 'required|string|max:1024',
            'sort_order' => 'integer|min:0',
        ]);

        $reply = QuickReply::create($request->only('title', 'body', 'sort_order'));

        return response()->json(['status' => 'ok', 'data' => $reply], 201);
    }

    // DELETE /api/quick-replies/{id} — eliminar respuesta rápida (solo admin)
    public function destroyQuickReply(int $id): JsonResponse
    {
        QuickReply::findOrFail($id)->delete();

        return response()->json(['status' => 'ok']);
    }
}
