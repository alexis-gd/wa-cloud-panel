<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reports\AgentConversationReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporte de conversaciones por agente, en pantalla y descargable.
 *
 * Visible de operador hacia arriba: el agente no ve la carga de sus compañeros.
 */
class AgentReportController extends Controller
{
    /** GET /api/reports/agent-conversations */
    public function index(Request $request): JsonResponse
    {
        [$desde, $hasta, $userId, $error] = $this->filtros($request);

        if ($error) {
            return $error;
        }

        $rows = AgentConversationReport::build($desde, $hasta, $userId);

        return response()->json([
            'status' => 'ok',
            'data'   => $rows,
            'meta'   => [
                'from'   => $desde,
                'to'     => $hasta,
                'totals' => AgentConversationReport::totals($rows),
            ],
        ]);
    }

    /** GET /api/reports/agent-conversations/export?format=xlsx|pdf */
    public function export(Request $request): Response|StreamedResponse|JsonResponse
    {
        [$desde, $hasta, $userId, $error] = $this->filtros($request);

        if ($error) {
            return $error;
        }

        $rows   = AgentConversationReport::build($desde, $hasta, $userId);
        $totals = AgentConversationReport::totals($rows);
        $nombre = 'conversaciones_por_agente_' . $desde . '_a_' . $hasta;

        return $request->input('format') === 'pdf'
            ? $this->pdf($rows, $totals, $desde, $hasta, $nombre)
            : $this->xlsx($rows, $totals, $desde, $hasta, $nombre);
    }

    /**
     * Valida y normaliza los filtros. Sin fecha se usa HOY: el reporte más pedido es el del
     * día, y así el primer render de la pantalla no necesita que el operador elija nada.
     *
     * @return array{0: string, 1: string, 2: ?int, 3: ?JsonResponse}
     */
    private function filtros(Request $request): array
    {
        $data = $request->validate([
            'from'    => 'nullable|date_format:Y-m-d',
            'to'      => 'nullable|date_format:Y-m-d',
            'user_id' => 'nullable|integer|exists:users,id',
            'format'  => 'nullable|in:xlsx,pdf',
        ]);

        $hoy   = now('America/Mexico_City')->format('Y-m-d');
        $desde = $data['from'] ?? $hoy;
        $hasta = $data['to']   ?? $desde;

        if ($hasta < $desde) {
            return ['', '', null, response()->json([
                'status'  => 'error',
                'message' => 'La fecha final es anterior a la inicial.',
                'code'    => 'INVALID_RANGE',
            ], 422)];
        }

        return [$desde, $hasta, $data['user_id'] ?? null, null];
    }

    private function pdf(array $rows, array $totals, string $desde, string $hasta, string $nombre): Response
    {
        $pdf = Pdf::loadView('reports.agent-conversations', [
            'rows'   => $rows,
            'totals' => $totals,
            'desde'  => $desde,
            'hasta'  => $hasta,
            'genera' => now('America/Mexico_City')->format('Y-m-d H:i'),
        ])->setPaper('letter');

        return $pdf->download($nombre . '.pdf');
    }

    private function xlsx(array $rows, array $totals, string $desde, string $hasta, string $nombre): StreamedResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Por agente');

        $periodo = $desde === $hasta ? $desde : "{$desde} a {$hasta}";

        $sheet->fromArray(['Conversaciones por agente'], null, 'A1');
        $sheet->fromArray(["Periodo: {$periodo}"], null, 'A2');
        $sheet->fromArray(['Agente', 'Rol', 'Recibidas en el periodo', 'Abiertas ahora'], null, 'A4');

        $row = 5;
        foreach ($rows as $r) {
            $sheet->fromArray([
                $r['agent'],
                $this->rolEnEspanol($r['role']),
                $r['received'],
                $r['open_now'],
            ], null, "A{$row}");
            $row++;
        }

        $sheet->fromArray(['Total', '', $totals['received'], $totals['open_now']], null, "A{$row}");
        $sheet->getStyle("A{$row}:D{$row}")->getFont()->setBold(true);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A4:D4')->getFont()->setBold(true);
        $sheet->getStyle("C5:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        foreach (range('A', 'D') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $nombre . '.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function rolEnEspanol(string $role): string
    {
        return ['admin' => 'Administrador', 'operator' => 'Operador', 'agent' => 'Agente'][$role] ?? $role;
    }
}
