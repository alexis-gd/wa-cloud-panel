<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\MessageLog;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * GET /api/export/contacts
     * Descarga todos los contactos como .xlsx
     */
    public function contacts(): StreamedResponse
    {
        $contacts = Contact::orderBy('id')->get([
            'id', 'phone', 'name', 'status', 'source', 'snoozed_until', 'created_at',
        ]);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Contactos');

        $sheet->fromArray(['ID', 'Teléfono', 'Nombre', 'Estado', 'Fuente', 'Snooze hasta', 'Creado'], null, 'A1');

        $row = 2;
        foreach ($contacts as $c) {
            $sheet->fromArray([
                $c->id,
                $c->phone,
                $c->name,
                $c->status,
                $c->source,
                $c->snoozed_until?->format('Y-m-d H:i') ?? '',
                $c->created_at->format('Y-m-d H:i'),
            ], null, "A{$row}");
            $row++;
        }

        // Autoajustar ancho de columnas
        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $this->streamXlsx($spreadsheet, 'contactos_' . now()->format('Ymd_His') . '.xlsx');
    }

    /**
     * GET /api/export/messages
     * Descarga los últimos 10,000 mensajes enviados como .xlsx
     */
    public function messages(): StreamedResponse
    {
        $logs = MessageLog::with('phoneNumber:id,display_name')
            ->orderByDesc('sent_at')
            ->limit(10000)
            ->get(['id', 'phone_number_id', 'to_number', 'template_name', 'language_code', 'status', 'wa_message_id', 'sent_at']);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Mensajes');

        $sheet->fromArray(['ID', 'Número origen', 'Destino', 'Plantilla', 'Idioma', 'Estado', 'WA Message ID', 'Enviado'], null, 'A1');

        $row = 2;
        foreach ($logs as $log) {
            $sheet->fromArray([
                $log->id,
                $log->phoneNumber?->display_name ?? $log->phone_number_id,
                $log->to_number,
                $log->template_name,
                $log->language_code,
                $log->status,
                $log->wa_message_id ?? '',
                $log->sent_at?->format('Y-m-d H:i:s') ?? '',
            ], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $this->streamXlsx($spreadsheet, 'mensajes_' . now()->format('Ymd_His') . '.xlsx');
    }

    private function streamXlsx(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        $writer = new Xlsx($spreadsheet);

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }
}
