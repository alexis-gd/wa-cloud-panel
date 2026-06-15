<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    // GET /api/notifications — 20 más recientes con conteo de no leídas
    public function index(): JsonResponse
    {
        $unreadCount = AppNotification::whereNull('read_at')->count();

        $notifications = AppNotification::orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(fn (AppNotification $n) => [
                'id'         => $n->id,
                'type'       => $n->type,
                'title'      => $n->title,
                'body'       => $n->body,
                'read'       => $n->read_at !== null,
                'created_at' => $n->created_at
                    ? Carbon::parse($n->getRawOriginal('created_at'), 'UTC')
                        ->setTimezone('America/Mexico_City')
                        ->format('Y-m-d H:i')
                    : null,
            ]);

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'unread_count'  => $unreadCount,
                'notifications' => $notifications,
            ],
        ]);
    }

    // POST /api/notifications/read-all — marcar todas como leídas
    public function markReadAll(): JsonResponse
    {
        AppNotification::whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['status' => 'ok']);
    }

    // DELETE /api/notifications/{id} — borrar una notificación
    public function destroy(int $id): JsonResponse
    {
        AppNotification::findOrFail($id)->delete();

        return response()->json(['status' => 'ok']);
    }
}
