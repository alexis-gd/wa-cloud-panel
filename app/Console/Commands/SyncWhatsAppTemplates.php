<?php

namespace App\Console\Commands;

use App\Models\PhoneNumber;
use App\Models\WaTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SyncWhatsAppTemplates extends Command
{
    protected $signature   = 'wa:sync-templates';
    protected $description = 'Sincroniza plantillas desde la API de Meta (estado, calidad, contenido)';

    public function handle(): int
    {
        $wabaId  = config('services.whatsapp.waba_id');
        $version = config('services.whatsapp.api_version');

        // Usar el token del número activo
        $phone = PhoneNumber::where('is_active', true)->first();

        if (!$phone) {
            $this->error('No hay número de WhatsApp activo. Configura uno en la BD.');
            return self::FAILURE;
        }

        $this->info('Consultando plantillas en Meta...');

        $response = Http::withToken($phone->token)
            ->get("https://graph.facebook.com/{$version}/{$wabaId}/message_templates", [
                'fields' => 'name,language,status,components',
                'limit'  => 100,
            ]);

        if ($response->failed()) {
            $this->error('Error al conectar con Meta: ' . $response->body());
            Log::error('wa:sync-templates falló', ['response' => $response->json()]);
            return self::FAILURE;
        }

        $templates = $response->json('data', []);
        $synced    = 0;
        $alerts    = [];

        foreach ($templates as $tpl) {
            $components = $tpl['components'] ?? [];

            $parsed = $this->parseComponents($components);

            $qualityScore     = data_get($tpl, 'quality_score.score');
            $rejectionReason  = $tpl['rejected_reason'] ?? null;

            $record = WaTemplate::updateOrCreate(
                ['name' => $tpl['name'], 'language_code' => $tpl['language']],
                [
                    'status'           => strtolower($tpl['status']),
                    'category'         => 'MARKETING',
                    'quality_score'    => $qualityScore,
                    'rejection_reason' => $rejectionReason,
                    'is_active'        => strtolower($tpl['status']) === 'approved',
                    ...$parsed,
                ],
            );

            $synced++;

            // Detectar alertas
            if (in_array($qualityScore, ['RED', 'YELLOW'])) {
                $alerts[] = "⚠️  {$tpl['name']}: calidad {$qualityScore}";
            }

            if (strtolower($tpl['status']) === 'rejected') {
                $alerts[] = "❌  {$tpl['name']}: RECHAZADA — {$rejectionReason}";
            }

            if (strtolower($tpl['status']) === 'paused') {
                $alerts[] = "⏸️  {$tpl['name']}: PAUSADA por Meta";
            }
        }

        $this->info("✅  {$synced} plantillas sincronizadas.");

        if (!empty($alerts)) {
            $this->newLine();
            $this->warn('Alertas encontradas:');
            foreach ($alerts as $alert) {
                $this->line("  {$alert}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Extrae header, body, footer y botones de los componentes de Meta.
     */
    private function parseComponents(array $components): array
    {
        $result = [
            'header_type'      => null,
            'header_text'      => null,
            'header_image_url' => null,
            'body_text'        => null,
            'footer_text'      => null,
            'buttons'          => null,
        ];

        foreach ($components as $component) {
            $type = strtoupper($component['type'] ?? '');

            switch ($type) {
                case 'HEADER':
                    $format = strtoupper($component['format'] ?? 'NONE');
                    $result['header_type'] = $format;

                    if ($format === 'TEXT') {
                        $result['header_text'] = $component['text'] ?? null;
                    } elseif ($format === 'IMAGE') {
                        $result['header_image_url'] = data_get($component, 'example.header_handle.0');
                    }
                    break;

                case 'BODY':
                    $result['body_text'] = $component['text'] ?? null;
                    break;

                case 'FOOTER':
                    $result['footer_text'] = $component['text'] ?? null;
                    break;

                case 'BUTTONS':
                    $result['buttons'] = array_map(fn ($btn) => [
                        'type' => strtoupper($btn['type'] ?? 'QUICK_REPLY'),
                        'text' => $btn['text'] ?? '',
                    ], $component['buttons'] ?? []);
                    break;
            }
        }

        return $result;
    }
}
