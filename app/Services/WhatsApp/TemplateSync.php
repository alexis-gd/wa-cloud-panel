<?php

namespace App\Services\WhatsApp;

use App\Models\PhoneNumber;
use App\Models\WaTemplate;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza las plantillas del WABA configurado (`WA_WABA_ID`) contra la BD local.
 *
 * Espeja: lo que no está en Meta se borra de la BD. Antes solo agregaba/actualizaba, así que
 * al cambiar de cuenta (o al borrar una plantilla en Meta) el panel seguía ofreciendo
 * plantillas que ya no existen - y la única forma de limpiarlas era entrar por SSH.
 * Borrar filas es seguro: `campaigns.template_name` guarda el nombre como texto, no como
 * llave foránea, así que las campañas viejas conservan su historial.
 */
class TemplateSync
{
    /** Tope de páginas por si Meta devolviera un cursor que no avanza (una WABA topa en 250). */
    private const MAX_PAGES = 10;

    public function __construct(private readonly WhatsAppClient $client) {}

    /**
     * @return array{ok: bool, synced: int, removed: int, alerts: array<int, string>, error: ?string}
     */
    public function run(): array
    {
        $wabaId = config('services.whatsapp.waba_id');

        // El token es a nivel cuenta y vive cifrado en el número activo.
        $phone = PhoneNumber::where('is_active', true)->first();

        if (! $phone) {
            return $this->fail('No hay número de WhatsApp activo. Configura uno en la BD.');
        }

        $fetched = $this->fetchAll($wabaId, $phone->token);

        if ($fetched['error'] !== null) {
            Log::error('TemplateSync falló', ['error' => $fetched['error']]);

            return $this->fail($fetched['error']);
        }

        $alerts = [];
        $keep   = [];

        foreach ($fetched['templates'] as $tpl) {
            $status       = strtolower($tpl['status'] ?? '');
            $qualityScore = data_get($tpl, 'quality_score.score');

            // Meta manda "NONE" cuando la plantilla NO está rechazada. Guardarlo tal cual hacía
            // que el panel mostrara "Rechazada: NONE" en plantillas aprobadas.
            $rejectionReason = $tpl['rejected_reason'] ?? null;
            $rejectionReason = in_array(strtoupper((string) $rejectionReason), ['', 'NONE'], true)
                ? null
                : $rejectionReason;

            WaTemplate::updateOrCreate(
                ['name' => $tpl['name'], 'language_code' => $tpl['language']],
                [
                    'status'           => $status,
                    'category'         => 'MARKETING',
                    'quality_score'    => $qualityScore,
                    'rejection_reason' => $rejectionReason,
                    'is_active'        => $status === 'approved',
                    ...$this->parseComponents($tpl['components'] ?? []),
                ],
            );

            $keep[] = $this->key($tpl['name'], $tpl['language']);

            if (in_array($qualityScore, ['RED', 'YELLOW'], true)) {
                $alerts[] = "⚠️  {$tpl['name']}: calidad {$qualityScore}";
            }

            if ($status === 'rejected') {
                $alerts[] = "❌  {$tpl['name']}: RECHAZADA - {$rejectionReason}";
            }

            if ($status === 'paused') {
                $alerts[] = "⏸️  {$tpl['name']}: PAUSADA por Meta";
            }
        }

        $removed = $this->prune($keep);

        return [
            'ok'      => true,
            'synced'  => count($fetched['templates']),
            'removed' => $removed,
            'alerts'  => $alerts,
            'error'   => null,
        ];
    }

    /**
     * Trae todas las plantillas paginando. Sin esto, una WABA con más de 100 plantillas haría
     * que la limpieza borrara las que no cupieron en la primera página.
     *
     * @return array{templates: array<int, array<string, mixed>>, error: ?string}
     */
    private function fetchAll(?string $wabaId, string $token): array
    {
        $templates = [];
        $after     = null;
        $pages     = 0;

        do {
            $query = ['fields' => 'name,language,status,components,quality_score,rejected_reason', 'limit' => 100];

            if ($after !== null) {
                $query['after'] = $after;
            }

            $res = $this->client->get("{$wabaId}/message_templates", $token, $query);

            if (! $res['ok']) {
                $message = data_get($res['body'], 'error.message', 'Error al conectar con Meta');

                return ['templates' => [], 'error' => $message];
            }

            $templates = array_merge($templates, $res['body']['data'] ?? []);
            $after     = data_get($res['body'], 'paging.cursors.after');
            $hasNext   = data_get($res['body'], 'paging.next') !== null;
            $pages++;
        } while ($hasNext && $after !== null && $pages < self::MAX_PAGES);

        return ['templates' => $templates, 'error' => null];
    }

    /** Borra las plantillas locales que ya no existen en el WABA actual. */
    private function prune(array $keep): int
    {
        $removed = 0;

        foreach (WaTemplate::all() as $local) {
            if (in_array($this->key($local->name, $local->language_code), $keep, true)) {
                continue;
            }

            $local->delete();
            $removed++;
        }

        if ($removed > 0) {
            Log::info('TemplateSync: plantillas retiradas (ya no están en el WABA)', ['removed' => $removed]);
        }

        return $removed;
    }

    private function key(string $name, string $language): string
    {
        return $name . '|' . $language;
    }

    /** @return array{ok: bool, synced: int, removed: int, alerts: array<int, string>, error: string} */
    private function fail(string $message): array
    {
        return ['ok' => false, 'synced' => 0, 'removed' => 0, 'alerts' => [], 'error' => $message];
    }

    /**
     * Extrae header, body, footer y botones de los componentes de Meta.
     *
     * @return array<string, mixed>
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
