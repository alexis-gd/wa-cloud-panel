<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ContactController extends Controller
{
    /**
     * Lista paginada de contactos.
     * GET /api/contacts?status=active&page=1
     */
    public function index(Request $request): JsonResponse
    {
        $query = Contact::with('tags:id,name,slug')->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('q')) {
            $term = $request->input('q');
            $query->where(function ($q) use ($term) {
                $q->where('phone', 'like', "%{$term}%")
                  ->orWhere('name',  'like', "%{$term}%");
            });
        }

        if ($request->filled('tag_id')) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', (int) $request->input('tag_id')));
        }

        $contacts = $query->paginate(50);

        return response()->json($contacts);
    }

    /**
     * Estadísticas rápidas de contactos.
     * GET /api/contacts/stats
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'total'     => Contact::count(),
            'active'    => Contact::where('status', 'active')->count(),
            'opted_out' => Contact::where('status', 'opted_out')->count(),
            'invalid'   => Contact::where('status', 'invalid')->count(),
        ]);
    }

    /**
     * Carga masiva desde Excel/CSV.
     * POST /api/contacts/upload
     *
     * El archivo debe tener:
     *   - Columna A (o "telefono"/"phone"): número de teléfono
     *   - Columna B (o "nombre"/"name"): nombre (opcional)
     *
     * Retorna resumen: total, inserted, duplicates, invalid.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240', // máx 10 MB
        ]);

        $file = $request->file('file');
        $path = $file->getRealPath();
        $extension = strtolower($file->getClientOriginalExtension());

        try {
            if ($extension === 'csv') {
                $spreadsheet = IOFactory::load($path, \PhpOffice\PhpSpreadsheet\Reader\Csv::class);
            } else {
                $spreadsheet = IOFactory::load($path);
            }
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'No se pudo leer el archivo: ' . $e->getMessage(),
            ], 422);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $rows  = $sheet->toArray(null, true, true, false);

        if (empty($rows)) {
            return response()->json(['error' => 'El archivo está vacío.'], 422);
        }

        // Detectar si la primera fila es encabezado
        $firstRow   = array_map('mb_strtolower', array_map('trim', $rows[0]));
        $hasHeader  = in_array('telefono', $firstRow)
                   || in_array('phone',    $firstRow)
                   || in_array('número',   $firstRow)
                   || in_array('numero',   $firstRow)
                   || in_array('celular',  $firstRow);
        $dataRows   = $hasHeader ? array_slice($rows, 1) : $rows;

        // Detectar posición de columnas si hay encabezado
        $phoneCol = 0;
        $nameCol  = 1;
        if ($hasHeader) {
            foreach ($firstRow as $i => $header) {
                if (in_array($header, ['telefono', 'phone', 'número', 'numero', 'celular'])) {
                    $phoneCol = $i;
                }
                if (in_array($header, ['nombre', 'name', 'contacto'])) {
                    $nameCol = $i;
                }
            }
        }

        $summary = [
            'total'      => 0,
            'inserted'   => 0,
            'duplicates' => 0,
            'invalid'    => 0,
            'errors'     => [],
        ];

        foreach ($dataRows as $rowIndex => $row) {
            $rawPhone = trim((string) ($row[$phoneCol] ?? ''));

            // Ignorar filas vacías
            if ($rawPhone === '') {
                continue;
            }

            $summary['total']++;

            $normalized = Contact::normalizePhone($rawPhone);

            if ($normalized === null) {
                $summary['invalid']++;
                if (count($summary['errors']) < 10) {
                    $summary['errors'][] = "Fila " . ($rowIndex + ($hasHeader ? 2 : 1)) . ": '{$rawPhone}' no es un número válido";
                }
                continue;
            }

            // Verificar duplicado
            if (Contact::where('phone', $normalized)->exists()) {
                $summary['duplicates']++;
                continue;
            }

            $name = trim((string) ($row[$nameCol] ?? ''));

            Contact::create([
                'phone'  => $normalized,
                'name'   => $name ?: null,
                'status' => 'active',
                'source' => 'excel',
            ]);

            $summary['inserted']++;
        }

        return response()->json([
            'success' => true,
            'summary' => $summary,
        ]);
    }

    /**
     * Editar nombre de un contacto (solo admin).
     * PUT /api/contacts/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'name' => 'nullable|string|max:255',
        ]);

        $contact = Contact::findOrFail($id);
        $contact->name = $request->input('name') ?: null;
        $contact->save();

        return response()->json(['status' => 'ok', 'data' => $contact]);
    }

    /**
     * Opt-out manual de un contacto.
     * DELETE /api/contacts/{id}
     */
    public function optOut(int $id): JsonResponse
    {
        $contact = Contact::findOrFail($id);
        $contact->optOut();

        return response()->json(['success' => true]);
    }
}
