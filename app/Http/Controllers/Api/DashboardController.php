<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

        // ── Stats mensuales ──
        $tz         = 'America/Mexico_City';
        $now        = Carbon::now($tz);
        $monthStart = $now->copy()->startOfMonth()->utc();
        $monthEnd   = $now->copy()->endOfMonth()->utc();

        $monthlySent   = MessageLog::whereBetween('created_at', [$monthStart, $monthEnd])
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->count();
        $monthlyGoal   = (int) Setting::get('monthly_goal', 200000);
        $daysRemaining = (int) $now->daysInMonth - (int) $now->day;
        $pct           = $monthlyGoal > 0 ? min(100, round($monthlySent / $monthlyGoal * 100, 1)) : 0;

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
                'monthly' => [
                    'sent'           => $monthlySent,
                    'goal'           => $monthlyGoal,
                    'pct'            => $pct,
                    'days_remaining' => $daysRemaining,
                    'month_label'    => $now->locale('es')->isoFormat('MMMM YYYY'),
                ],
            ],
        ]);
    }

    // GET /api/dashboard/messages — mensajes recientes con filtros y paginación
    public function messages(Request $request): JsonResponse
    {
        $query = MessageLog::with('phoneNumber:id,display_name')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('phone_number_id')) {
            $query->where('phone_number_id', (int) $request->phone_number_id);
        }

        $paginated = $query->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'status' => 'ok',
            'data'   => $paginated->items(),
            'meta'   => [
                'total'    => $paginated->total(),
                'page'     => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'pages'    => $paginated->lastPage(),
            ],
        ]);
    }

    // GET /api/dashboard/daily-stats — envíos por día (últimos 14 días)
    public function dailyStats(): JsonResponse
    {
        $tz    = 'America/Mexico_City';
        $start = Carbon::now($tz)->subDays(13)->startOfDay()->utc();
        $end   = Carbon::now($tz)->endOfDay()->utc();

        $rows = MessageLog::select(
                DB::raw("DATE(CONVERT_TZ(created_at, '+00:00', '-06:00')) as day"),
                'status',
                DB::raw('COUNT(*) as total')
            )
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('status', ['sent', 'delivered', 'read', 'failed'])
            ->groupBy('day', 'status')
            ->orderBy('day')
            ->get();

        // Construir serie de 14 días con ceros por default
        $days = collect();
        for ($i = 13; $i >= 0; $i--) {
            $days->push(Carbon::now($tz)->subDays($i)->format('Y-m-d'));
        }

        $indexed = $rows->groupBy('day');

        $series = $days->map(fn (string $day) => [
            'day'       => $day,
            'sent'      => (int) ($indexed->get($day)?->firstWhere('status', 'sent')?->total      ?? 0),
            'delivered' => (int) ($indexed->get($day)?->firstWhere('status', 'delivered')?->total ?? 0),
            'read'      => (int) ($indexed->get($day)?->firstWhere('status', 'read')?->total      ?? 0),
            'failed'    => (int) ($indexed->get($day)?->firstWhere('status', 'failed')?->total    ?? 0),
        ]);

        return response()->json(['status' => 'ok', 'data' => $series]);
    }
}
