<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendSmsMessage;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\SmsTemplate;
use App\Models\Tag;
use App\Models\WaTemplate;
use App\Services\PhoneNumberSelector;
use App\Services\WhatsApp\SendWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CampaignController extends Controller
{
    public function __construct(private readonly PhoneNumberSelector $selector) {}

    // Conteo de contactos activos que recibiría una campaña según su tag.
    // COUNT(*) indexado — barato aunque haya cientos de miles de contactos.
    private function countTargetContacts(?int $tagId): int
    {
        $query = Contact::active();

        if ($tagId) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $tagId));
        }

        return $query->count();
    }

    // GET /api/campaigns
    public function index(): JsonResponse
    {
        $campaigns = Campaign::with(['phoneNumber', 'smsTemplate:id,name'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'status' => 'ok',
            'data'   => $campaigns->items(),
            'meta'   => [
                'total'    => $campaigns->total(),
                'page'     => $campaigns->currentPage(),
                'per_page' => $campaigns->perPage(),
            ],
        ]);
    }

    // POST /api/campaigns
    public function store(Request $request): JsonResponse
    {
        // channel default whatsapp para compatibilidad con campañas ya existentes.
        $channel = $request->input('channel', 'whatsapp');

        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'channel'       => 'nullable|in:whatsapp,sms',
            'template_name' => 'required_if:channel,whatsapp|nullable|string|max:255',
            'language_code' => 'required_if:channel,whatsapp|nullable|string|max:10',
            'body_vars'     => 'array',
            'body_vars.*'   => 'string',
            // SMS ahora exige plantilla (no texto libre): garantiza que se usó una plantilla.
            'sms_template_id' => 'required_if:channel,sms|nullable|integer|exists:sms_templates,id',
            'tag_id'        => 'nullable|integer|exists:tags,id',
        ]);

        return $channel === 'sms'
            ? $this->storeSms($data)
            : $this->storeWhatsApp($data);
    }

    // Crea una campaña WhatsApp: exige plantilla aprobada + número WA activo.
    private function storeWhatsApp(array $data): JsonResponse
    {
        // Solo plantillas aprobadas (regla seguridad inquebrantable)
        $template = WaTemplate::where('name', $data['template_name'])
            ->where('status', 'approved')
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Plantilla no encontrada o no aprobada.',
                'code'    => 'INVALID_TEMPLATE',
            ], 422);
        }

        // Plantilla con imagen y sin archivo local = Meta no entrega ninguno de los mensajes
        // (la URL del CDN de Meta es de vista previa). Se frena aquí en vez de dejar que la
        // campaña salga y falle contacto por contacto.
        if ($template->needs_image) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Esta plantilla lleva imagen y todavía no se ha subido. Ve a Plantillas y súbela antes de usarla.',
                'code'    => 'TEMPLATE_IMAGE_MISSING',
            ], 422);
        }

        $phone = PhoneNumber::where('is_active', true)->first();
        if (! $phone) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No hay número de teléfono activo configurado.',
                'code'    => 'NO_PHONE_NUMBER',
            ], 422);
        }

        $campaign = Campaign::create([
            'name'            => $data['name'],
            'channel'         => 'whatsapp',
            'template_name'   => $data['template_name'],
            'language_code'   => $data['language_code'],
            'body_vars'       => $data['body_vars'] ?? [],
            'tag_id'          => $data['tag_id'] ?? null,
            'phone_number_id' => $phone->id,
            'status'          => 'draft',
            'total_contacts'  => $this->countTargetContacts($data['tag_id'] ?? null),
            'sent_count'      => 0,
            'delivered_count' => 0,
            'failed_count'    => 0,
        ]);

        return response()->json(['status' => 'ok', 'data' => $campaign], 201);
    }

    // Crea una campaña SMS: exige plantilla SMS activa (no texto libre). El cuerpo se toma de
    // la plantilla y se guarda como snapshot en sms_body (para que el envío no cambie si la
    // plantilla se edita/borra después). El gateway resuelve el pool de chips (sin número WA).
    private function storeSms(array $data): JsonResponse
    {
        $template = SmsTemplate::where('id', $data['sms_template_id'])
            ->where('is_active', true)
            ->first();

        if (! $template) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Plantilla SMS no encontrada o inactiva.',
                'code'    => 'INVALID_SMS_TEMPLATE',
            ], 422);
        }

        $campaign = Campaign::create([
            'name'            => $data['name'],
            'channel'         => 'sms',
            'sms_body'        => $template->body,   // snapshot del cuerpo
            'sms_template_id' => $template->id,
            'tag_id'          => $data['tag_id'] ?? null,
            'phone_number_id' => null,
            'status'          => 'draft',
            'total_contacts'  => $this->countTargetContacts($data['tag_id'] ?? null),
            'sent_count'      => 0,
            'delivered_count' => 0,
            'failed_count'    => 0,
        ]);

        return response()->json(['status' => 'ok', 'data' => $campaign->load('smsTemplate:id,name')], 201);
    }

    // GET /api/campaigns/{id}
    public function show(int $id): JsonResponse
    {
        $campaign = Campaign::with('phoneNumber')->find($id);

        if (! $campaign) {
            return response()->json(['status' => 'error', 'message' => 'Campaña no encontrada.'], 404);
        }

        return response()->json(['status' => 'ok', 'data' => $campaign]);
    }

    // POST /api/campaigns/{id}/execute
    public function execute(int $id): JsonResponse
    {
        $campaign = Campaign::find($id);

        if (! $campaign) {
            return response()->json(['status' => 'error', 'message' => 'Campaña no encontrada.'], 404);
        }

        if (! in_array($campaign->status, ['draft', 'paused'])) {
            return response()->json([
                'status'  => 'error',
                'message' => "La campaña está en estado '{$campaign->status}' y no puede ejecutarse.",
                'code'    => 'INVALID_STATUS',
            ], 422);
        }

        // ── Verificar ventana horaria 9AM-10PM CST (L-V) — SOLO WhatsApp ──
        // SMS no tiene horario forzado: el cliente elige cuándo (ver contexto-sms).
        // La ventana la resuelve SendWindow (misma fuente que el job de envío). El "modo
        // demo" (Setting schedule_bypass=1) la abre para pruebas; default apagado — en
        // operación real NUNCA se enciende (enviar fuera de horario puede generar reportes
        // de spam en Meta). El guardia real vive además en el job, por si la cola avanza
        // fuera de hora (worker 24/7).
        if ($campaign->channel === 'whatsapp' && ! SendWindow::isOpen()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Los envíos solo están permitidos de lunes a viernes entre 9:00 AM y 10:00 PM, hora del centro de México (CDMX, GMT-6).',
                'code'    => 'OUTSIDE_SCHEDULE',
            ], 422);
        }

        $contactsQuery = Contact::active();

        if ($campaign->tag_id) {
            $contactsQuery->whereHas('tags', fn ($q) => $q->where('tags.id', $campaign->tag_id));
        }

        $contacts = $contactsQuery->get();

        if ($contacts->isEmpty()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No hay contactos activos para enviar.',
                'code'    => 'NO_CONTACTS',
            ], 422);
        }

        // ── Campaña SMS: el gateway resuelve el pool de chips, no hay selector de número ──
        if ($campaign->channel === 'sms') {
            $campaign->update([
                'status'         => 'running',
                'total_contacts' => $contacts->count(),
                'started_at'     => now(),
            ]);

            Log::info("Campaña SMS #{$campaign->id} iniciada", [
                'campaign' => $campaign->name,
                'contacts' => $contacts->count(),
            ]);

            foreach ($contacts as $contact) {
                SendSmsMessage::dispatch($contact->id, $campaign->id, $campaign->sms_body);
            }

            return response()->json([
                'status' => 'ok',
                'data'   => [
                    'campaign_id'     => $campaign->id,
                    'jobs_dispatched' => $contacts->count(),
                    'channel'         => 'sms',
                    'message'         => "Se encolaron {$contacts->count()} SMS.",
                ],
            ]);
        }

        // ── Balanceo multi-número: seleccionar números disponibles ──
        $phoneNumbers = $this->selector->available();

        if ($phoneNumbers->isEmpty()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No hay números de teléfono disponibles (todos pausados o sin capacidad).',
                'code'    => 'NO_PHONE_AVAILABLE',
            ], 422);
        }

        $campaign->update([
            'status'         => 'running',
            'total_contacts' => $contacts->count(),
            'started_at'     => now(),
        ]);

        $phoneCount = $phoneNumbers->count();

        Log::info("Campaña #{$campaign->id} iniciada", [
            'campaign'      => $campaign->name,
            'contacts'      => $contacts->count(),
            'template'      => $campaign->template_name,
            'phone_numbers' => $phoneNumbers->pluck('id')->all(),
        ]);

        // Distribuir contactos en round-robin entre números disponibles
        foreach ($contacts as $index => $contact) {
            $phone = $phoneNumbers[$index % $phoneCount];

            SendWhatsAppMessage::dispatch(
                $contact->id,
                $campaign->id,
                $phone->id,
                $campaign->template_name,
                $campaign->language_code,
                $campaign->body_vars ?? [],
            );
        }

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'campaign_id'     => $campaign->id,
                'jobs_dispatched' => $contacts->count(),
                'phone_numbers'   => $phoneCount,
                'message'         => "Se encolaron {$contacts->count()} mensajes entre {$phoneCount} número(s).",
            ],
        ]);
    }

    // GET /api/campaigns/{id}/logs
    public function logs(int $id, Request $request): JsonResponse
    {
        $campaign = Campaign::find($id);

        if (! $campaign) {
            return response()->json(['status' => 'error', 'message' => 'Campaña no encontrada.'], 404);
        }

        // simplePaginate hace 1 sola query (SELECT ... LIMIT 51) en vez de 2.
        // El índice idx_logs_campaign_sent cubre (campaign_id, sent_at) y hace
        // que ORDER BY sent_at DESC LIMIT 50 sea instantáneo aunque haya 200k filas.
        $logs = MessageLog::where('campaign_id', $id)
            ->orderByDesc('sent_at')
            ->simplePaginate(50, ['*'], 'page', $request->integer('page', 1));

        // Stats: sent y pending vienen de los contadores del campaign (sin query extra).
        // Solo consultamos discarded porque no tiene contador propio en la tabla campaigns.
        // Usamos el índice campaign_id para contar solo las filas de esta campaña.
        $discardedCount = MessageLog::where('campaign_id', $id)
            ->where('status', 'discarded')
            ->count();

        // Info-only (punto 4): contactos del segmento que están de BAJA (opted_out) y por eso
        // NO se les envía - se filtran antes de despachar, así que no generan fila. Este contador
        // les da visibilidad en el detalle. NO toca la cola ni la lógica de envío.
        $excludedOptOut = Contact::where('status', 'opted_out')
            ->when($campaign->tag_id, fn ($q) => $q->whereHas('tags', fn ($t) => $t->where('tags.id', $campaign->tag_id)))
            ->count();

        // Para draft recalculamos el total en vivo: refleja imports/opt-outs hechos
        // después de crear la campaña. COUNT(*) indexado, costo trivial.
        $totalContacts = $campaign->status === 'draft'
            ? $this->countTargetContacts($campaign->tag_id)
            : $campaign->total_contacts;

        $sentCount   = $campaign->sent_count;
        $failedCount = max(0, $campaign->failed_count - $discardedCount);
        $pending     = max(0, $totalContacts - $campaign->sent_count - $campaign->failed_count);

        // Incluir el campaign fresco para que el frontend no use datos rancios del listado
        $freshCampaign = $campaign->fresh();

        // ── Calcular cuándo reanudarán los jobs pendientes ──
        $resumesAt = null;
        if ($pending > 0) {
            $tz    = 'America/Mexico_City';
            $phone = PhoneNumber::where('is_active', true)->orderByDesc('daily_limit')->first();

            if ($phone && $phone->isPaused()) {
                // Circuit breaker activo: reanudan cuando se desbloquee el número
                $resumesAt = $phone->paused_until
                    ->setTimezone($tz)
                    ->locale('es')
                    ->isoFormat('dddd D [de] MMMM [a las] H:mm');
            } else {
                // Límite diario alcanzado: los jobs se liberarán al siguiente día hábil a las 9AM
                $candidate = Carbon::now($tz)->addDay()->startOfDay()->addHours(9);
                while (in_array($candidate->dayOfWeek, [Carbon::SATURDAY, Carbon::SUNDAY])) {
                    $candidate->addDay();
                }
                $resumesAt = $candidate->locale('es')->isoFormat('dddd D [de] MMMM [a las] H:mm');
            }
        }

        return response()->json([
            'status'   => 'ok',
            'campaign' => [
                'id'             => $freshCampaign->id,
                'name'           => $freshCampaign->name,
                'status'         => $freshCampaign->status,
                'sent_count'     => $freshCampaign->sent_count,
                'failed_count'   => $freshCampaign->failed_count,
                'total_contacts' => $totalContacts,
                'completed_at'   => $freshCampaign->completed_at,
            ],
            'stats'  => [
                'sent'      => $sentCount,
                'failed'    => $failedCount,
                'discarded' => $discardedCount,
                'pending'   => $pending,
                'resumes_at' => $resumesAt,
                'excluded_optout' => $excludedOptOut,
            ],
            // sent_at se formatea en CST para que el frontend muestre la hora local de México,
            // no la hora UTC cruda (que diferiría hasta 6 horas de lo que el operador ve en su reloj).
            'data'      => collect($logs->items())->map(fn ($log) => array_merge($log->toArray(), [
                'sent_at' => $log->sent_at?->setTimezone('America/Mexico_City')->format('Y-m-d H:i'),
            ]))->values(),
            'has_more'  => $logs->hasMorePages(),
            'next_page' => $logs->hasMorePages() ? ($request->integer('page', 1) + 1) : null,
            'prev_page' => $request->integer('page', 1) > 1 ? ($request->integer('page', 1) - 1) : null,
        ]);
    }

    // POST /api/campaigns/{id}/pause
    public function pause(int $id): JsonResponse
    {
        $campaign = Campaign::find($id);

        if (! $campaign) {
            return response()->json(['status' => 'error', 'message' => 'Campaña no encontrada.'], 404);
        }

        if ($campaign->status !== 'running') {
            return response()->json([
                'status'  => 'error',
                'message' => "Solo se puede pausar una campaña en ejecución. Estado actual: '{$campaign->status}'.",
                'code'    => 'INVALID_STATUS',
            ], 422);
        }

        $campaign->update(['status' => 'paused']);

        return response()->json(['status' => 'ok', 'data' => $campaign->fresh()]);
    }

    // POST /api/campaigns/{id}/retry-pending
    // Re-encola los contactos que aún no tienen ningún log en esta campaña.
    // Útil cuando los jobs se perdieron (ej: queue:clear accidental) y la campaña
    // quedó en 'running' para siempre.
    public function retryPending(int $id): JsonResponse
    {
        $campaign = Campaign::find($id);

        if (! $campaign) {
            return response()->json(['status' => 'error', 'message' => 'Campaña no encontrada.'], 404);
        }

        if ($campaign->status !== 'running') {
            return response()->json([
                'status'  => 'error',
                'message' => "Solo se puede re-despachar una campaña en ejecución. Estado actual: '{$campaign->status}'.",
                'code'    => 'INVALID_STATUS',
            ], 422);
        }

        // Misma puerta de horario que execute() - solo WhatsApp. El job igual frena de fondo
        // fuera de ventana, pero aquí avisamos al operador en vez de encolar jobs que esperarían.
        if ($campaign->channel === 'whatsapp' && ! SendWindow::isOpen()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Los envíos solo están permitidos de lunes a viernes entre 9:00 AM y 10:00 PM, hora del centro de México (CDMX, GMT-6).',
                'code'    => 'OUTSIDE_SCHEDULE',
            ], 422);
        }

        $pending = $campaign->total_contacts - $campaign->sent_count - $campaign->failed_count;

        if ($pending <= 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No hay mensajes pendientes en esta campaña.',
                'code'    => 'NO_PENDING',
            ], 422);
        }

        // Contactos que ya tienen algún log (ya se procesaron, con cualquier resultado)
        $alreadyProcessed = MessageLog::where('campaign_id', $id)->pluck('to_number')->all();

        // Contactos activos del mismo segmento que aún no tienen log
        $contactsQuery = Contact::active();
        if ($campaign->tag_id) {
            $contactsQuery->whereHas('tags', fn ($q) => $q->where('tags.id', $campaign->tag_id));
        }
        $contacts = $contactsQuery->whereNotIn('phone', $alreadyProcessed)->get();

        if ($contacts->isEmpty()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No se encontraron contactos pendientes de procesar.',
                'code'    => 'NO_CONTACTS',
            ], 422);
        }

        if ($campaign->channel === 'sms') {
            foreach ($contacts as $contact) {
                SendSmsMessage::dispatch($contact->id, $campaign->id, $campaign->sms_body);
            }
        } else {
            $phoneNumbers = $this->selector->available();

            if ($phoneNumbers->isEmpty()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'No hay números de teléfono disponibles (todos pausados o sin capacidad).',
                    'code'    => 'NO_PHONE_AVAILABLE',
                ], 422);
            }

            $phoneCount = $phoneNumbers->count();

            foreach ($contacts as $index => $contact) {
                $phone = $phoneNumbers[$index % $phoneCount];

                SendWhatsAppMessage::dispatch(
                    $contact->id,
                    $campaign->id,
                    $phone->id,
                    $campaign->template_name,
                    $campaign->language_code,
                    $campaign->body_vars ?? [],
                );
            }
        }

        // Ajustar total_contacts para que checkAutoComplete funcione correctamente
        // con el nuevo set de jobs: sent + failed (ya procesados) + nuevos dispatched
        $campaign->update([
            'total_contacts' => $campaign->sent_count + $campaign->failed_count + $contacts->count(),
        ]);

        Log::info("Campaña #{$campaign->id} — retry-pending", [
            'campaign'        => $campaign->name,
            'contacts_redispatch' => $contacts->count(),
        ]);

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'jobs_dispatched' => $contacts->count(),
                'message'         => "Se re-encolaron {$contacts->count()} mensaje(s) pendiente(s).",
            ],
        ]);
    }

    // DELETE /api/campaigns/{id}
    public function destroy(int $id): JsonResponse
    {
        $campaign = Campaign::find($id);

        if (! $campaign) {
            return response()->json(['status' => 'error', 'message' => 'Campaña no encontrada.'], 404);
        }

        if ($campaign->status !== 'draft') {
            return response()->json([
                'status'  => 'error',
                'message' => "Solo se pueden borrar campañas en borrador. Estado actual: '{$campaign->status}'.",
                'code'    => 'INVALID_STATUS',
            ], 422);
        }

        $campaign->delete();

        return response()->json(['status' => 'ok', 'data' => null]);
    }
}
