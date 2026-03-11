<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\WaTemplate;
use App\Services\WhatsApp\TemplateBuilder;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function __construct(
        private WhatsAppClient $client,
        private TemplateBuilder $builder,
    ) {}

    // GET /api/templates
    public function index(): JsonResponse
    {
        return response()->json(WaTemplate::where('is_active', true)->get());
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
