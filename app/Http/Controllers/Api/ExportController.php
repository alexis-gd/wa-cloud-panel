<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Services\StatusLabels;
use Illuminate\Support\Carbon;
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

        $sheet->fromArray(['ID', 'Teléfono', 'Nombre', 'Estado', 'Fuente', 'Pospuesto hasta', 'Creado'], null, 'A1');

        $row = 2;
        foreach ($contacts as $c) {
            $sheet->fromArray([
                $c->id,
                $c->phone,
                $c->name,
                StatusLabels::contactStatus($c->status),
                StatusLabels::contactSource($c->source),
                self::enHoraDeMexico($c->snoozed_until),
                self::enHoraDeMexico($c->created_at),
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
            ->get(['id', 'phone_number_id', 'channel', 'to_number', 'template_name', 'language_code', 'status', 'wa_message_id', 'sent_at']);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Mensajes');

        $sheet->fromArray(['ID', 'Canal', 'Número origen', 'Destino', 'Plantilla', 'Idioma', 'Estado', 'ID del mensaje', 'Enviado'], null, 'A1');

        $row = 2;
        foreach ($logs as $log) {
            $sheet->fromArray([
                $log->id,
                StatusLabels::channel($log->channel),
                $log->phoneNumber?->display_name ?? $log->phone_number_id,
                $log->to_number,
                $log->template_name,
                $log->language_code,
                StatusLabels::messageStatus($log->status),
                $log->wa_message_id ?? '',
                self::enHoraDeMexico($log->sent_at, conSegundos: true),
            ], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $this->streamXlsx($spreadsheet, 'mensajes_' . now()->format('Ymd_His') . '.xlsx');
    }

    /**
     * Las fechas se guardan en UTC pero el Excel lo lee el equipo en México: sin convertir,
     * un envío de las 8 de la noche aparecía como de las 2 de la mañana.
     */
    private static function enHoraDeMexico(?Carbon $fecha, bool $conSegundos = false): string
    {
        if (! $fecha) {
            return '';
        }

        return $fecha->setTimezone('America/Mexico_City')
            ->format($conSegundos ? 'Y-m-d H:i:s' : 'Y-m-d H:i');
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
