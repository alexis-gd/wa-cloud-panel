<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\PhoneNumber;
use App\Models\WaTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CampaignController extends Controller
{
    // GET /api/campaigns
    public function index(): JsonResponse
    {
        $campaigns = Campaign::with('phoneNumber')
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
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'template_name' => 'required|string|max:255',
            'language_code' => 'required|string|max:10',
            'body_vars'     => 'array',
            'body_vars.*'   => 'string',
        ]);

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
            'template_name'   => $data['template_name'],
            'language_code'   => $data['language_code'],
            'body_vars'       => $data['body_vars'] ?? [],
            'phone_number_id' => $phone->id,
            'status'          => 'draft',
            'total_contacts'  => 0,
            'sent_count'      => 0,
            'delivered_count' => 0,
            'failed_count'    => 0,
        ]);

        return response()->json(['status' => 'ok', 'data' => $campaign], 201);
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

        // ── Verificar ventana horaria 9AM-10PM CST (L-V) ──
        $now     = now('America/Mexico_City');
        $hour    = (int) $now->format('G');
        $weekday = (int) $now->format('N'); // 1=Lunes, 7=Domingo

        if ($weekday > 5 || $hour < 9 || $hour >= 22) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Los envíos solo están permitidos de lunes a viernes entre 9:00 AM y 10:00 PM (hora México).',
                'code'    => 'OUTSIDE_SCHEDULE',
            ], 422);
        }

        $contacts = Contact::active()->get();

        if ($contacts->isEmpty()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'No hay contactos activos para enviar.',
                'code'    => 'NO_CONTACTS',
            ], 422);
        }

        $campaign->update([
            'status'         => 'running',
            'total_contacts' => $contacts->count(),
            'started_at'     => now(),
        ]);

        Log::info("Campaña #{$campaign->id} iniciada", [
            'campaign'    => $campaign->name,
            'contacts'    => $contacts->count(),
            'template'    => $campaign->template_name,
        ]);

        foreach ($contacts as $contact) {
            SendWhatsAppMessage::dispatch(
                $contact->id,
                $campaign->id,
                $campaign->phone_number_id,
                $campaign->template_name,
                $campaign->language_code,
                $campaign->body_vars ?? [],
            );
        }

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'campaign_id'    => $campaign->id,
                'jobs_dispatched' => $contacts->count(),
                'message'        => "Se encolaron {$contacts->count()} mensajes.",
            ],
        ]);
    }
}
