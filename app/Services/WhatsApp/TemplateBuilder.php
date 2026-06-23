<?php

namespace App\Services\WhatsApp;

use App\Models\WaTemplate;

class TemplateBuilder
{
    /**
     * Construye el payload JSON para enviar una plantilla aprobada por Meta.
     * Incluye el componente header (IMAGE/TEXT) si la plantilla lo tiene.
     *
     * @param string $to           Número destino con código de país, ej. "521234567890"
     * @param string $templateName Nombre exacto de la plantilla en Meta, ej. "hello_world"
     * @param string $languageCode Código de idioma, ej. "es_MX", "en_US"
     * @param array  $bodyVars     Variables del cuerpo {{1}}, {{2}}, etc. (puede estar vacío)
     */
    public function build(string $to, string $templateName, string $languageCode, array $bodyVars = []): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'     => $templateName,
                'language' => ['code' => $languageCode],
            ],
        ];

        $components = [];

        // Header — solo si la plantilla tiene header_type IMAGE o TEXT con URL
        $template = WaTemplate::where('name', $templateName)->first();

        if ($template?->header_type === 'IMAGE') {
            $imageUrl = $this->resolveImageUrl($templateName, $template->header_image_url);

            if ($imageUrl) {
                $components[] = [
                    'type'       => 'header',
                    'parameters' => [
                        [
                            'type'  => 'image',
                            'image' => ['link' => $imageUrl],
                        ],
                    ],
                ];
            }
        }

        // Body vars
        if (!empty($bodyVars)) {
            $components[] = [
                'type'       => 'body',
                'parameters' => array_map(
                    fn($var) => ['type' => 'text', 'text' => (string) $var],
                    $bodyVars
                ),
            ];
        }

        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }

        return $payload;
    }

    private function resolveImageUrl(string $templateName, ?string $fallback): ?string
    {
        $localPath = public_path("storage/templates/{$templateName}.jpg");

        if (file_exists($localPath)) {
            $base = rtrim(config('services.whatsapp.media_base_url', ''), '/');
            if ($base) {
                return "{$base}/storage/templates/{$templateName}.jpg";
            }
        }

        return $fallback;
    }
}
