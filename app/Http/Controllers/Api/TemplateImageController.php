<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WaTemplate;
use App\Services\WhatsApp\TemplateImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Imagen de encabezado de una plantilla. Existe como controller aparte porque el archivo no
 * es un campo de la plantilla: vive en disco con el nombre de la plantilla y solo lo usa el
 * envío. Antes solo se podía poner por SSH, así que el cliente no podía crear plantillas con
 * imagen sin nosotros.
 */
class TemplateImageController extends Controller
{
    public function __construct(private readonly TemplateImage $image) {}

    // POST /api/templates/{id}/image
    public function store(Request $request, int $id): JsonResponse
    {
        $template = WaTemplate::findOrFail($id);

        if ($template->header_type !== 'IMAGE') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Esta plantilla no lleva imagen de encabezado.',
                'code'    => 'TEMPLATE_WITHOUT_IMAGE_HEADER',
            ], 422);
        }

        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:' . TemplateImage::MAX_KB],
        ], [
            'image.required' => 'Elige una imagen.',
            'image.mimes'    => 'La imagen debe ser JPG o PNG.',
            'image.max'      => 'La imagen no puede pesar más de 5 MB.',
        ]);

        $this->image->store($template->name, $request->file('image'));

        return response()->json([
            'status' => 'ok',
            'data'   => $this->present($template),
        ]);
    }

    // DELETE /api/templates/{id}/image
    public function destroy(int $id): JsonResponse
    {
        $template = WaTemplate::findOrFail($id);

        $this->image->delete($template->name);

        return response()->json([
            'status' => 'ok',
            'data'   => $this->present($template),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(WaTemplate $template): array
    {
        return [
            'id'          => $template->id,
            'name'        => $template->name,
            'image_url'   => $template->image_url,
            'needs_image' => $template->needs_image,
        ];
    }
}
