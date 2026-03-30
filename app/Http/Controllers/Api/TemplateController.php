<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\SyncWhatsAppTemplates;
use App\Http\Controllers\Controller;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\WaTemplate;
use App\Services\WhatsApp\TemplateBuilder;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class TemplateController extends Controller
{
    public function __construct(
        private WhatsAppClient $client,
        private TemplateBuilder $builder,
    ) {}

    // GET /api/templates
    public function index(): JsonResponse
    {
        $templates = WaTemplate::orderBy('created_at', 'desc')->get();
        return response()->json(['status' => 'ok', 'data' => $templates]);
    }

    // POST /api/templates
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'          => 'required|string|max:512|unique:wa_templates,name',
            'language_code' => 'required|string|max:10',
            'category'      => 'required|in:MARKETING,UTILITY,AUTHENTICATION',
            'description'   => 'nullable|string|max:500',
        ]);

        $template = WaTemplate::create([
            ...$data,
            'status'    => 'approved',
            'is_active' => true,
        ]);

        return response()->json(['status' => 'ok', 'data' => $template], 201);
    }

    // PUT /api/templates/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $template = WaTemplate::findOrFail($id);

        $data = $request->validate([
            'description' => 'nullable|string|max:500',
            'is_active'   => 'boolean',
        ]);

        $template->update($data);

        return response()->json(['status' => 'ok', 'data' => $template->fresh()]);
    }

    // DELETE /api/templates/{id}
    public function destroy(int $id): JsonResponse
    {
        WaTemplate::findOrFail($id)->delete();
        return response()->json(['status' => 'ok']);
    }

    // POST /api/templates/sync — sincroniza desde Meta API
    public function sync(): JsonResponse
    {
        $exitCode = Artisan::call('wa:sync-templates');

        if ($exitCode !== 0) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Error al sincronizar con Meta. Revisa que el token sea válido.',
            ], 500);
        }

        $templates = WaTemplate::orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'ok',
            'data'   => $templates,
            'synced' => $templates->count(),
        ]);
    }

    // POST /api/templates/send-test
    public function sendTest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_name' => 'required|string',
            'language_code' => 'required|string',
            'to'            => 'required|string',
            'body_vars'     => 'array',
        ]);

        $phone = PhoneNumber::where('is_active', true)->firstOrFail();

        // Regla #2: crear log ANTES de llamar a la API
        $log = MessageLog::logSend(
            $phone->id,
            $data['to'],
            $data['template_name'],
            $data['language_code'],
            $data['body_vars'] ?? []
        );

        $payload  = $this->builder->build($data['to'], $data['template_name'], $data['language_code'], $data['body_vars'] ?? []);
        $response = $this->client->post($phone->phone_number_id, $phone->token, $payload);

        $log->updateFromResponse($response);

        return response()->json([
            'log_id'  => $log->id,
            'status'  => $log->fresh()->status,
            'wa_response' => $response['body'],
        ], $response['ok'] ? 200 : 422);
    }
}
