<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\TemplateSync;
use Illuminate\Console\Command;

class SyncWhatsAppTemplates extends Command
{
    protected $signature   = 'wa:sync-templates';
    protected $description = 'Sincroniza plantillas desde la API de Meta (estado, calidad, contenido)';

    public function handle(TemplateSync $sync): int
    {
        $this->info('Consultando plantillas en Meta...');

        $result = $sync->run();

        if (! $result['ok']) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->info("✅  {$result['synced']} plantillas sincronizadas.");

        if ($result['removed'] > 0) {
            $this->info("🧹  {$result['removed']} plantilla(s) retirada(s): ya no existen en esta cuenta de WhatsApp.");
        }

        if (! empty($result['alerts'])) {
            $this->newLine();
            $this->warn('Alertas encontradas:');
            foreach ($result['alerts'] as $alert) {
                $this->line("  {$alert}");
            }
        }

        return self::SUCCESS;
    }
}
