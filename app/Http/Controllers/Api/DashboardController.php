<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\MessageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    // GET /api/dashboard/stats
    public function stats(): JsonResponse
    {
        $messageTotals = MessageLog::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $contactTotals = Contact::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $logs = MessageLog::with('phoneNumber:id,display_name')
            ->latest()
            ->limit(20)
            ->get();

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'stats' => [
                    'sent'      => (int) ($messageTotals['sent']      ?? 0),
                    'delivered' => (int) ($messageTotals['delivered'] ?? 0),
                    'read'      => (int) ($messageTotals['read']      ?? 0),
                    'failed'    => (int) ($messageTotals['failed']    ?? 0),
                ],
                'contacts' => [
                    'total'     => (int) Contact::count(),
                    'active'    => (int) ($contactTotals['active']    ?? 0),
                    'opted_out' => (int) ($contactTotals['opted_out'] ?? 0),
                    'invalid'   => (int) ($contactTotals['invalid']   ?? 0),
                ],
                'recent_messages' => $logs,
            ],
        ]);
    }
}
