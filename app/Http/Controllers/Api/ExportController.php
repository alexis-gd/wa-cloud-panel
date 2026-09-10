<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Services\StatusLabels;
use App\Services\WhatsApp\DeliveryReason;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Contactos');

        $sheet->fromArray(
            ['ID', 'Teléfono', 'Nombre', 'Estado', 'Fuente', 'Etiquetas', 'Pospuesto hasta', 'Creado'],
            null,
            'A1'
        );

        $row = 2;

        // `chunk()` y no `get()`: con 200,000 contactos y sus etiquetas cargadas, traerlos
        // todos de una se come la memoria del proceso. Se recorre de 1,000 en 1,000.
        // La columna Etiquetas sale separada por coma, el mismo formato que lee el importador,
        // así que el archivo exportado se puede volver a subir para re-etiquetar.
        Contact::with('tags:id,name')
            ->orderBy('id')
            ->chunk(1000, function ($contacts) use ($sheet, &$row) {
                foreach ($contacts as $c) {
                    $sheet->fromArray([
                        $c->id,
                        $c->phone,
                        $c->name,
                        StatusLabels::contactStatus($c->status),
                        StatusLabels::contactSource($c->source),
                        $c->tags->pluck('name')->implode(', '),
                        self::enHoraDeMexico($c->snoozed_until),
                        self::enHoraDeMexico($c->created_at),
                    ], null, "A{$row}");
                    $row++;
                }
            });

        // Autoajustar ancho de columnas
        foreach (range('A', 'H') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return $this->streamXlsx($spreadsheet, 'contactos_' . now()->format('Ymd_His') . '.xlsx');
    }

    /**
     * GET /api/export/messages
     * Descarga hasta 10,000 mensajes como .xlsx, respetando el filtro del panel.
     *
     * El botón exportaba SIEMPRE todo: el operador filtraba "Fallidos", veía 1,368 y se
     * descargaba 8,000 filas que no había pedido. Filtrar además abarata el export - menos
     * filas que leer y que escribir en la hoja, y `status` va por índice.
     */
    public function messages(Request $request): StreamedResponse
    {
        // Las tres columnas de error y `discard_reason` no se pintan tal cual: alimentan a
        // DeliveryReason, que es quien decide el texto. Sin ellas en el `get()` el Excel decia
        // solo "Fallido" y el cliente tenia que preguntarnos por que.
        $query = MessageLog::with('phoneNumber:id,display_name');

        // Mismos filtros que `DashboardController::messages()`, para que lo que se descarga sea
        // exactamente lo que el operador está viendo.
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('phone_number_id')) {
            $query->where('phone_number_id', $request->integer('phone_number_id'));
        }

        $logs = $query
            ->orderByDesc('sent_at')
            ->limit(10000)
            ->get([
                'id', 'phone_number_id', 'channel', 'to_number', 'template_name', 'language_code',
                'status', 'wa_message_id', 'sent_at',
                'error_message', 'delivery_error_code', 'delivery_error_title', 'discard_reason',
            ]);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Mensajes');

        $sheet->fromArray(['ID', 'Canal', 'Número origen', 'Destino', 'Plantilla', 'Idioma', 'Estado', 'Motivo', 'ID del mensaje', 'Enviado'], null, 'A1');

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
                // Texto largo y con prefijo de quien lo dijo ("Meta respondió: ..."): el Excel
                // se lee fuera del panel, sin tooltip donde esconder el detalle.
                DeliveryReason::forLog($log)['full'] ?? '',
                $log->wa_message_id ?? '',
                self::enHoraDeMexico($log->sent_at, conSegundos: true),
            ], null, "A{$row}");
            $row++;
        }

        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // El nombre del archivo dice qué trae: con varios exports en la carpeta de descargas,
        // "mensajes_fallidos_..." y "mensajes_..." se distinguen de un vistazo.
        $sufijo = $request->filled('status')
            ? '_' . Str::slug(StatusLabels::messageStatus($request->string('status')))
            : '';

        return $this->streamXlsx($spreadsheet, 'mensajes' . $sufijo . '_' . now()->format('Ymd_His') . '.xlsx');
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
