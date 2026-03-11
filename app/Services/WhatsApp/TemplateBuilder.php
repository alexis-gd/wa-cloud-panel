<?php

namespace App\Services\WhatsApp;

class TemplateBuilder
{
    /**
     * Construye el payload JSON para enviar una plantilla aprobada por Meta.
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

        if (!empty($bodyVars)) {
            $parameters = array_map(fn($var) => ['type' => 'text', 'text' => (string) $var], $bodyVars);

            $payload['template']['components'] = [
                [
                    'type'       => 'body',
                    'parameters' => $parameters,
                ],
            ];
        }

        return $payload;
    }
}
