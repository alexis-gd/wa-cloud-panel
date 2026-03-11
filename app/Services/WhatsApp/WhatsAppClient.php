<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppClient
{
    private string $baseUrl;

    public function __construct()
    {
        $version = config('services.whatsapp.api_version');
        $this->baseUrl = "https://graph.facebook.com/{$version}";
    }

    /**
     * Único punto de salida HTTP hacia la API de Meta.
     * Todos los envíos deben pasar por aquí.
     */
    public function post(string $phoneNumberId, string $token, array $payload): array
    {
        $url = "{$this->baseUrl}/{$phoneNumberId}/messages";

        $response = Http::withToken($token)
            ->timeout(15)
            ->post($url, $payload);

        if ($response->failed()) {
            Log::error('WhatsApp API error', [
                'status'  => $response->status(),
                'body'    => $response->json(),
                'payload' => $payload,
            ]);
        }

        return [
            'status' => $response->status(),
            'body'   => $response->json() ?? [],
            'ok'     => $response->successful(),
        ];
    }
}
