<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Plantillas de SMS locales (no pasan por Meta). CRUD simple para la pestana SMS de
 * la vista Plantillas. El envio de prueba reutiliza POST /api/sms/send-test.
 */
class SmsTemplateController extends Controller
{
    // GET /api/sms-templates
    public function index(): JsonResponse
    {
        $templates = SmsTemplate::orderByDesc('created_at')->get();

        return response()->json(['status' => 'ok', 'data' => $templates]);
    }

    // POST /api/sms-templates
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:sms_templates,name',
            'body' => 'required|string|max:1000',
        ]);

        $template = SmsTemplate::create([
            'name'      => $data['name'],
            'body'      => $data['body'],
            'is_active' => true,
        ]);

        return response()->json(['status' => 'ok', 'data' => $template], 201);
    }

    // PUT /api/sms-templates/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $template = SmsTemplate::findOrFail($id);

        $data = $request->validate([
            'name'      => "sometimes|string|max:255|unique:sms_templates,name,{$id}",
            'body'      => 'sometimes|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);

        $template->update($data);

        return response()->json(['status' => 'ok', 'data' => $template->fresh()]);
    }

    // DELETE /api/sms-templates/{id}
    public function destroy(int $id): JsonResponse
    {
        SmsTemplate::findOrFail($id)->delete();

        return response()->json(['status' => 'ok']);
    }
}
