<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Services\WhatsApp\PortfolioLimit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Capacidad real de envío por día. Meta limita POR PORTFOLIO (compartido por todos los
     * números), así que NO se suma el daily_limit de cada número: el tope efectivo es el menor
     * entre el límite del portfolio y la suma de los throttles por número. Si Meta aún no
     * reportó el límite, cae a la suma por número.
     */
    private function dailySendCapacity(): int
    {
        $sumPerNumber = (int) PhoneNumber::where('is_active', true)->sum('daily_limit');
        $portfolio    = PortfolioLimit::daily();

        return $portfolio === null ? $sumPerNumber : min($portfolio, $sumPerNumber);
    }

    // GET /api/dashboard/stats
    public function stats(): JsonResponse
    {
        $messageTotals = MessageLog::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $contactTotals = Contact::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        // ── Stats mensuales basados en capacidad real ──
        $tz         = 'America/Mexico_City';
        $now        = Carbon::now($tz);
        $monthStart = $now->copy()->startOfMonth()->utc();
        $monthEnd   = $now->copy()->endOfMonth()->utc();

        $monthlySent = MessageLog::whereBetween('created_at', [$monthStart, $monthEnd])
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->count();

        // Días hábiles totales y restantes en el mes actual
        [$workingDaysTotal, $workingDaysRemaining] = $this->countWorkingDays($now);

        // Capacidad = tope real de envío/día (portfolio compartido, no suma) × días hábiles
        $totalDailyLimit = $this->dailySendCapacity();
        $capacity         = $totalDailyLimit * $workingDaysTotal;
        $pct              = $capacity > 0 ? min(100, round($monthlySent / $capacity * 100, 1)) : 0;

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
                    'total'       => (int) Contact::count(),
                    'active'      => (int) ($contactTotals['active']      ?? 0),
                    'opted_out'   => (int) ($contactTotals['opted_out']   ?? 0),
                    'invalid'     => (int) ($contactTotals['invalid']     ?? 0),
                    'unreachable' => (int) ($contactTotals['unreachable'] ?? 0),
                ],
                'monthly' => [
                    'sent'                  => $monthlySent,
                    'capacity'              => $capacity,
                    'pct'                   => $pct,
                    'working_days_total'    => $workingDaysTotal,
                    'working_days_remaining'=> $workingDaysRemaining,
                    'daily_limit'           => $totalDailyLimit,
                    'month_label'           => $now->locale('es')->isoFormat('MMMM YYYY'),
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

        $items = collect($paginated->items())->map(fn (MessageLog $log) => array_merge(
            $log->toArray(),
            ['created_at' => $log->created_at->setTimezone('America/Mexico_City')->format('Y-m-d H:i')]
        ));

        return response()->json([
            'status' => 'ok',
            'data'   => $items,
            'meta'   => [
                'total'    => $paginated->total(),
                'page'     => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'pages'    => $paginated->lastPage(),
            ],
        ]);
    }

    // GET /api/dashboard/daily-stats — envíos por día del mes en curso
    public function dailyStats(): JsonResponse
    {
        $tz    = 'America/Mexico_City';
        $now   = Carbon::now($tz);
        $start = $now->copy()->startOfMonth()->utc();
        $end   = $now->copy()->endOfDay()->utc();

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

        // Construir serie desde el día 1 del mes hasta hoy
        $days    = collect();
        $daysInMonth = (int) $now->daysInMonth;
        $today   = (int) $now->day;

        for ($d = 1; $d <= $today; $d++) {
            $days->push($now->copy()->setDay($d)->format('Y-m-d'));
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

    // GET /api/dashboard/monthly-history — histórico de los últimos 6 meses
    // 1 sola query GROUP BY año-mes en vez de 6 COUNT separados
    public function monthlyHistory(): JsonResponse
    {
        $tz              = 'America/Mexico_City';
        $now             = Carbon::now($tz);
        $totalDailyLimit = $this->dailySendCapacity();

        $rangeStart = $now->copy()->subMonths(5)->startOfMonth()->utc();
        $rangeEnd   = $now->copy()->endOfDay()->utc();

        // Una sola query — idx_logs_created_status cubre el WHERE
        $rows = MessageLog::select(
                DB::raw("DATE_FORMAT(CONVERT_TZ(created_at, '+00:00', '-06:00'), '%Y-%m') as ym"),
                DB::raw('COUNT(*) as total')
            )
            ->whereBetween('created_at', [$rangeStart, $rangeEnd])
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $history = collect();

        for ($i = 5; $i >= 0; $i--) {
            $month    = $now->copy()->subMonths($i);
            $ym       = $month->format('Y-m');
            $sent     = (int) ($rows[$ym] ?? 0);

            [$wdTotal,] = $this->countWorkingDays($month);
            $capacity   = $totalDailyLimit * $wdTotal;

            $history->push([
                'month'       => $month->format('Y-m'),
                'month_label' => $month->locale('es')->isoFormat('MMM YYYY'),
                'sent'        => $sent,
                'capacity'    => $capacity,
                'pct'         => $capacity > 0 ? min(100, round($sent / $capacity * 100, 1)) : 0,
            ]);
        }

        return response()->json(['status' => 'ok', 'data' => $history]);
    }

    /**
     * Cuenta días hábiles (L-V) en el mes de $date.
     * Devuelve [total_en_el_mes, restantes_desde_hoy_inclusive].
     */
    private function countWorkingDays(Carbon $date): array
    {
        $tz    = 'America/Mexico_City';
        $today = Carbon::now($tz)->startOfDay();
        $start = $date->copy()->startOfMonth();
        $end   = $date->copy()->endOfMonth();
        $total = 0;
        $remaining = 0;

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if (! in_array($d->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY])) {
                $total++;
                if ($d->gte($today)) {
                    $remaining++;
                }
            }
        }

        return [$total, $remaining];
    }
}
