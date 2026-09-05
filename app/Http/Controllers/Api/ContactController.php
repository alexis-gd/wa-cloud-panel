<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\Setting;
use App\Services\Contacts\ContactImporter;
use App\Services\Contacts\DeliverabilityBadges;
use App\Services\Contacts\DeliverabilityFilter;
use App\Services\Contacts\PhoneListParser;
use App\Support\PageSize;
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
        return $this->respondWithPage($request);
    }

    /**
     * Misma lista que index(), pero recibiendo el pegado masivo de números en el body.
     * POST /api/contacts/search
     *
     * Va por POST y no por GET a propósito: el operador copia una columna de Excel y pega
     * cientos de números. 500 números son ~6.5 KB de query string y 2,000 pasan de 25 KB -
     * nginx corta con 414 antes de que Laravel se entere. En el body no hay ese techo.
     */
    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'phones_raw'     => 'nullable|string|max:200000',
            'status'         => 'nullable|string',
            'q'              => 'nullable|string|max:255',
            'tag_id'         => 'nullable|integer',
            'deliverability' => 'nullable',
        ]);

        $paste = $request->filled('phones_raw')
            ? PhoneListParser::parse((string) $request->input('phones_raw'))
            : null;

        return $this->respondWithPage($request, $paste);
    }

    /**
     * Arma la página de contactos con los filtros del request. Compartido por index() y
     * search() para que GET y POST no se separen nunca.
     *
     * @param  array|null  $paste  Resultado de PhoneListParser cuando hubo pegado masivo.
     */
    private function respondWithPage(Request $request, ?array $paste = null): JsonResponse
    {
        $query = Contact::with('tags:id,name,slug')->orderByDesc('id');

        // El pegado masivo manda sobre el buscador de texto: si el operador pegó una lista,
        // lo que quiere ver es exactamente esa lista.
        if ($paste !== null) {
            // Lista vacía tras normalizar -> whereIn([]) devuelve 0 filas, que es lo correcto
            // (pegó puros números inválidos); el resumen del pegado se lo explica.
            $query->whereIn('phone', $paste['phones']);
        } elseif ($request->filled('q')) {
            $term = $request->input('q');
            $query->where(function ($q) use ($term) {
                $q->where('phone', 'like', "%{$term}%")
                  ->orWhere('name',  'like', "%{$term}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
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

        // Filtro por entregabilidad, por canal (Enfriamiento WhatsApp != Enfriamiento SMS).
        // Acumulativo: varios estados elegidos se suman (OR).
        DeliverabilityFilter::apply(
            $query,
            DeliverabilityFilter::normalize($request->input('deliverability'))
        );

        $contacts = $query->paginate(PageSize::from($request, 50));

        // Agregar estado de entregabilidad (cooldown / enviado hoy) a cada contacto.
        // Batch: 2 queries por página, no una por fila (seguro a escala de 200k).
        DeliverabilityBadges::attach($contacts->getCollection());

        // Se conserva la forma cruda del paginator (current_page/last_page/total): el front
        // ya la consume. Solo se suma el aviso de recorte de "Todos" y el resumen del pegado.
        return response()->json(array_merge($contacts->toArray(), [
            'capped'    => PageSize::wasCapped($request, $contacts->total()),
            'cap_limit' => PageSize::ALL_CAP,
            'paste'     => $paste === null ? null : $this->pasteSummary($paste),
        ]));
    }

    /**
     * Resumen del pegado masivo para el chip de la pantalla: cuántos se pegaron, cuántos
     * quedaron fuera por formato y cuáles NO están dados de alta en el sistema.
     *
     * Los faltantes se calculan sobre TODA la base, sin los demás filtros: "no está en el
     * sistema" es distinto de "no pasó el filtro de estado".
     */
    private function pasteSummary(array $paste): array
    {
        $existing = Contact::whereIn('phone', $paste['phones'])->pluck('phone')->all();
        $missing  = array_values(array_diff($paste['phones'], $existing));

        return [
            'pasted'          => $paste['pasted'],
            'valid'           => $paste['valid'],
            'invalid'         => $paste['invalid'],
            'invalid_samples' => $paste['invalid_samples'],
            'truncated'       => $paste['truncated'],
            'max_phones'      => PhoneListParser::MAX_PHONES,
            'found'           => count($existing),
            'missing_count'   => count($missing),
            'missing'         => array_slice($missing, 0, PhoneListParser::MAX_MISSING_LISTED),
        ];
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

        // Cooldown: último envío real (sent/delivered/read) dentro de la ventana (mínimo 7, default 30 días)
        $cooldownDays   = max(7, (int) Setting::get('cooldown_days', 30));
        $lastSent       = MessageLog::where('to_number', $phone)
            ->whereIn('status', ['sent', 'delivered', 'read'])
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
            // El archivo temporal de PHP no tiene extensión, así que el lector se elige por la
            // extensión ORIGINAL. Antes se pasaba la clase del lector como 2do argumento de
            // `load()`, pero ahí va un `int $flags`: cualquier CSV moría con TypeError -> 500.
            $reader = $extension === 'csv'
                ? IOFactory::createReader('Csv')
                : IOFactory::createReaderForFile($path);

            $spreadsheet = $reader->load($path);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'No se pudo leer el archivo: ' . $e->getMessage(),
            ], 422);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $rows  = $sheet->toArray(null, true, true, false);

        if (empty($rows)) {
            return response()->json(['error' => 'El archivo está vacío.'], 422);
        }

        // El parseo, el alta y el etiquetado viven en el servicio: el archivo puede traer
        // decenas de miles de filas y todo se resuelve por lotes, no fila por fila.
        $summary = (new ContactImporter())->import($rows);

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
