<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\MessageLog;
use App\Services\Sms\SmsGatewayClient;
use Illuminate\Console\Command;

/**
 * Red de seguridad para el estado de entrega SMS: le pregunta al gateway (server-a-server)
 * el estado de los SMS que quedaron en 'sent' y los actualiza a delivered/failed SIN depender
 * del webhook del teléfono. Corre cada 10 min desde el scheduler.
 *
 * Solo cubre el ESTADO DE ENTREGA. Las respuestas entrantes (sms:received) siguen llegando
 * por webhook del teléfono: eso no se puede pollear con la API 3rd-party.
 */
class ReconcileSmsStatus extends Command
{
    protected $signature = 'sms:reconcile-status {--limit=200}';

    protected $description = 'Consulta al gateway el estado de los SMS pendientes y actualiza delivered/failed sin webhook';

    public function handle(SmsGatewayClient $client): int
    {
        $limit = (int) $this->option('limit');

        // SMS en 'sent' con id del gateway, de las últimas 24h, y con al menos 3 min de vida
        // (le damos ese margen al webhook antes de pollear, para no duplicar trabajo).
        $logs = MessageLog::where('channel', 'sms')
            ->where('status', 'sent')
            ->whereNotNull('wa_message_id')
            ->where('sent_at', '>=', now()->subDay())
            ->where('sent_at', '<=', now()->subMinutes(3))
            ->orderBy('sent_at')
            ->limit($limit)
            ->get();

        $updated = 0;

        foreach ($logs as $log) {
            $res = $client->getState($log->wa_message_id);

            if (! $res['ok'] || ! $res['state']) {
                continue;
            }

            $state = strtolower((string) $res['state']);

            if ($state === 'delivered') {
                $log->updateStatus('delivered');
                $updated++;
            } elseif ($state === 'failed') {
                // Persistir el motivo que reporte el gateway (si lo hay) para que el detalle
                // de la campaña muestre el porqué, no un "-".
                $reason = is_string($res['error'] ?? null)
                    ? $res['error']
                    : ($res['error']['message'] ?? null);

                $log->update([
                    'status'        => 'failed',
                    'error_message' => $reason ?: 'El gateway reportó el envío como fallido (sin detalle)',
                ]);
                Contact::where('phone', $log->to_number)->first()?->registerSmsBounce();
                $updated++;
            }
        }

        if ($updated > 0) {
            $this->info("Reconciliados {$updated} SMS por polling.");
        }

        return self::SUCCESS;
    }
}
