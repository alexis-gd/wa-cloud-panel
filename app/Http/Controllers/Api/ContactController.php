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

        // Filtro "Solo bajas SMS": contactos que no reciben SMS (opt-out / bloqueado / inválido).
        // Eje independiente del status de WhatsApp.
        if ($request->boolean('sms_blocked')) {
            $query->where(function ($q) {
                $q->where('sms_opt_out', true)
                  ->orWhere('sms_blocked', true)
                  ->orWhere('sms_invalid', true);
            });
        }

        $contacts = $query->paginate(50);

        // Agregar estado de entregabilidad (cooldown / enviado hoy) a cada contacto.
        // Batch: 2 queries por página, no una por fila (seguro a escala de 200k).
        $this->attachDeliverability($contacts->getCollection());

        return response()->json($contacts);
    }

    /**
     * Anexa a cada contacto de la página su estado de entregabilidad:
     * sent_today, cooldown_active, cooldown_until, deliverable.
     * Hace 2 queries agregadas sobre message_log para toda la página (sin N+1).
     */
    private function attachDeliverability($contacts): void
    {
        if ($contacts->isEmpty()) {
            return;
        }

        $phones = $contacts->pluck('phone')->all();

        $startOfDay = now('America/Mexico_City')->startOfDay()->utc();
        $endOfDay   = now('America/Mexico_City')->endOfDay()->utc();

        // Dedup y cooldown son POR CANAL (igual que los jobs SendWhatsAppMessage/SendSmsMessage):
        // un SMS enviado hoy no pone a WhatsApp "en cooldown" y viceversa. Por eso calculamos
        // los sets de "enviado hoy" y "ultimo envio" filtrando por canal.
        $sentTodaySetFor = function (string $channel) use ($phones, $startOfDay, $endOfDay): array {
            return array_flip(
                MessageLog::whereIn('to_number', $phones)
                    ->where('channel', $channel)
                    ->whereBetween('sent_at', [$startOfDay, $endOfDay])
                    ->whereIn('status', ['sent', 'delivered', 'read'])
                    ->distinct()
                    ->pluck('to_number')
                    ->all()
            );
        };

        $lastSentMapFor = function (string $channel) use ($phones): array {
            return MessageLog::whereIn('to_number', $phones)
                ->where('channel', $channel)
                ->where('status', 'sent')
                ->groupBy('to_number')
                ->select('to_number', DB::raw('MAX(sent_at) as last_sent'))
                ->pluck('last_sent', 'to_number')
                ->all();
        };

        $waSentTodaySet  = $sentTodaySetFor('whatsapp');
        $waLastSentMap   = $lastSentMapFor('whatsapp');
        $smsSentTodaySet = $sentTodaySetFor('sms');
        $smsLastSentMap  = $lastSentMapFor('sms');

        $cooldownDays = max(7, (int) Setting::get('cooldown_days', 30));

        // Devuelve [activo(bool), hasta(string|null)] segun el ultimo envio del canal.
        $cooldownState = function (?string $lastSent) use ($cooldownDays): array {
            if ($lastSent && now()->diffInDays($lastSent) < $cooldownDays) {
                return [true, Carbon::parse($lastSent)
                    ->addDays($cooldownDays)
                    ->setTimezone('America/Mexico_City')
                    ->format('Y-m-d')];
            }
            return [false, null];
        };

        foreach ($contacts as $contact) {
            // Eje WhatsApp: la identidad del contacto (opted_out/invalid/unreachable) bloquea WA.
            $waBlocked = in_array($contact->status, ['opted_out', 'invalid', 'unreachable'], true);
            // Eje SMS: opt-out es cross-channel (una baja bloquea ambos), mas las banderas propias
            // de SMS. Un "invalid/unreachable" de WhatsApp NO implica que el SMS falle.
            $smsBlocked = $contact->status === 'opted_out'
                || $contact->sms_opt_out || $contact->sms_blocked || $contact->sms_invalid;

            $snoozeActive = $contact->isSnoozeActive();
            $snoozeUntil  = $snoozeActive
                ? $contact->snoozed_until->setTimezone('America/Mexico_City')->format('Y-m-d')
                : null;

            $waSentToday = isset($waSentTodaySet[$contact->phone]);
            [$waCooldownActive, $waCooldownUntil] = $cooldownState($waLastSentMap[$contact->phone] ?? null);

            $smsSentToday = isset($smsSentTodaySet[$contact->phone]);
            [$smsCooldownActive, $smsCooldownUntil] = $cooldownState($smsLastSentMap[$contact->phone] ?? null);

            $contact->setAttribute('snooze_active', $snoozeActive);
            $contact->setAttribute('snooze_until', $snoozeUntil);

            // Genericos = eje WhatsApp (retrocompatibles: el front viejo y /contacts/check los usaban).
            $contact->setAttribute('sent_today', $waSentToday);
            $contact->setAttribute('cooldown_active', $waCooldownActive);
            $contact->setAttribute('cooldown_until', $waCooldownUntil);
            $contact->setAttribute('deliverable', ! $waBlocked && ! $snoozeActive && ! $waSentToday && ! $waCooldownActive);

            // Eje SMS (nuevos, para el segundo tag de Entregabilidad).
            // El snooze NO entra aquí: es por canal (solo WhatsApp). Ver contexto-sms.
            $contact->setAttribute('sms_sent_today', $smsSentToday);
            $contact->setAttribute('sms_cooldown_active', $smsCooldownActive);
            $contact->setAttribute('sms_cooldown_until', $smsCooldownUntil);
            $contact->setAttribute('sms_deliverable', ! $smsBlocked && ! $smsSentToday && ! $smsCooldownActive);
        }
    }

    /**
     * Estadísticas rápidas de contactos.
     * GET /api/contacts/stats
     */
    public function stats(): JsonResponse
    {
        // 1 query con GROUP BY en vez de 4 COUNT separadas
        $counts = Contact::select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'total'     => $counts->sum(),
            'active'    => (int) ($counts['active']    ?? 0),
            'opted_out' => (int) ($counts['opted_out'] ?? 0),
            'invalid'   => (int) ($counts['invalid']   ?? 0),
        ]);
    }

    /**
     * Alta individual de un contacto (manual, sin Excel).
     * POST /api/contacts
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => 'required|string|max:20',
            'name'  => 'nullable|string|max:255',
        ]);

        $normalized = Contact::normalizePhone($data['phone']);

        if ($normalized === null) {
            return response()->json([
                'status'  => 'error',
                'message' => 'El teléfono no tiene un formato válido (México: 52 + 10 dígitos).',
                'code'    => 'INVALID_PHONE',
            ], 422);
        }

        // Rechazar duplicados — incluye opt-out/inválidos/unreachable (no se reincorporan,
        // se conservan para auditoría). El estado se devuelve para que el front lo explique.
        if (Contact::where('phone', $normalized)->exists()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Este número ya existe en el sistema.',
                'code'    => 'DUPLICATE',
                'data'    => $this->deliverabilitySnapshot($normalized),
            ], 422);
        }

        $contact = Contact::create([
            'phone'  => $normalized,
            'name'   => $data['name'] ?: null,
            'status' => 'active',
            'source' => 'manual',
        ]);

        return response()->json([
            'status' => 'ok',
            'data'   => $contact->load('tags:id,name,slug'),
        ], 201);
    }

    /**
     * Chequeo de entregabilidad de un número antes de darlo de alta.
     * GET /api/contacts/check?phone=X
     */
    public function check(Request $request): JsonResponse
    {
        $normalized = Contact::normalizePhone((string) $request->query('phone', ''));

        if ($normalized === null) {
            return response()->json([
                'status' => 'ok',
                'data'   => [
                    'phone'           => null,
                    'valid_format'    => false,
                    'exists'          => false,
                    'contact_status'  => null,
                    'name'            => null,
                    'blocked'         => false,
                    'snooze_active'   => false,
                    'snooze_until'    => null,
                    'cooldown_active' => false,
                    'cooldown_until'  => null,
                    'sent_today'      => false,
                    'deliverable'     => false,
                ],
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'data'   => $this->deliverabilitySnapshot($normalized),
        ]);
    }

    /**
     * Calcula el estado de entregabilidad de un número (ya normalizado):
     * si existe, si está bloqueado, en cooldown o ya recibió hoy.
     * Reutiliza la misma lógica que el job de envío.
     */
    private function deliverabilitySnapshot(string $phone): array
    {
        $contact = Contact::where('phone', $phone)->first();
        $status  = $contact?->status;
        $blocked = in_array($status, ['opted_out', 'invalid', 'unreachable'], true);

        // Snooze: el contacto pidió "No por ahora"
        $snoozeActive = (bool) $contact?->isSnoozeActive();
        $snoozeUntil  = $snoozeActive
            ? $contact->snoozed_until->setTimezone('America/Mexico_City')->format('Y-m-d')
            : null;

        // Dedup: ¿ya recibió un mensaje hoy? (hora México)
        $startOfDay = now('America/Mexico_City')->startOfDay()->utc();
        $endOfDay   = now('America/Mexico_City')->endOfDay()->utc();
        $sentToday  = MessageLog::where('to_number', $phone)
            ->whereBetween('sent_at', [$startOfDay, $endOfDay])
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->exists();

        // Cooldown: último 'sent' dentro de la ventana (mínimo 7, default 30 días)
        $cooldownDays   = max(7, (int) Setting::get('cooldown_days', 30));
        $lastSent       = MessageLog::where('to_number', $phone)
            ->where('status', 'sent')
            ->latest('sent_at')
            ->value('sent_at');
        $cooldownActive = false;
        $cooldownUntil  = null;

        if ($lastSent && now()->diffInDays($lastSent) < $cooldownDays) {
            $cooldownActive = true;
            $cooldownUntil  = Carbon::parse($lastSent)
                ->addDays($cooldownDays)
                ->setTimezone('America/Mexico_City')
                ->format('Y-m-d');
        }

        return [
            'phone'           => $phone,
            'valid_format'    => true,
            'exists'          => (bool) $contact,
            'contact_status'  => $status,
            'name'            => $contact?->name,
            'blocked'         => $blocked,
            'snooze_active'   => $snoozeActive,
            'snooze_until'    => $snoozeUntil,
            'cooldown_active' => $cooldownActive,
            'cooldown_until'  => $cooldownUntil,
            'sent_today'      => $sentToday,
            'deliverable'     => ! $blocked && ! $snoozeActive && ! $sentToday && ! $cooldownActive,
        ];
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
        $data = $request->validate([
            'name'   => 'sometimes|nullable|string|max:255',
            // Solo se admite reactivar (-> active). unreachable es el unico status reversible;
            // opt-out e invalid NO se reactivan por aqui (cumplimiento / numero sin WhatsApp).
            'status' => 'sometimes|in:active',
        ]);

        $contact = Contact::findOrFail($id);

        if ($request->has('name')) {
            $contact->name = $data['name'] ?: null;
        }

        if (($data['status'] ?? null) === 'active' && $contact->status !== 'active') {
            if ($contact->status !== 'unreachable') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Solo se puede reactivar un contacto inalcanzable.',
                    'code'    => 'NOT_REACTIVABLE',
                ], 422);
            }
            $contact->status = 'active';
        }

        $contact->save();

        return response()->json(['status' => 'ok', 'data' => $contact]);
    }

    /**
     * Opt-out manual de un contacto (cumplimiento — nunca más se le envía).
     * POST /api/contacts/{id}/opt-out
     */
    public function optOut(int $id): JsonResponse
    {
        $contact = Contact::findOrFail($id);
        $contact->optOut();

        return response()->json(['success' => true]);
    }

    /**
     * Soft delete de un contacto — para limpiar basura/pruebas (solo admin/superadmin).
     * El registro se conserva con deleted_at y queda fuera de listas y campañas.
     * Distinto del opt-out: esto es limpieza operativa, no cumplimiento.
     * DELETE /api/contacts/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $contact = Contact::findOrFail($id);
        $contact->delete(); // SoftDeletes: marca deleted_at

        return response()->json(['status' => 'ok', 'data' => null]);
    }
}
