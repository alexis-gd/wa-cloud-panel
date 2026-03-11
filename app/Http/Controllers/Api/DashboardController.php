<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    // GET /api/dashboard/stats
    public function stats(): JsonResponse
    {
        $logs = MessageLog::with('phoneNumber:id,display_name')
            ->latest()
            ->limit(20)
            ->get();

        $totals = MessageLog::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'recent_messages' => $logs,
            'totals'          => $totals,
        ]);
    }
}
