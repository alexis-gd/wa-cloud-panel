<?php

namespace App\Console\Commands;

use App\Services\Sms\SmsGatewayClient;
use Illuminate\Console\Command;

/**
 * Red de seguridad para los SMS ENTRANTES (respuestas). Análogo a sms:reconcile-status,
 * pero con una diferencia clave: los entrantes viven en el TELÉFONO, no en el servidor del
 * gateway, así que no se pueden pollear server-a-server. Lo único que se puede hacer es
 * pedirle al device que re-exporte los sms:received de una ventana: llegan por el mismo
 * webhook (POST /api/sms/webhook) y el dedup por gateway_message_id evita duplicar.
 *
 * Cubre el hueco de MIUI matando la app del gateway: si durante ese rato entraron respuestas,
 * el webhook en vivo nunca disparó y se perderían. Esta re-exportación las recupera.
 *
 * Corre cada hora desde el scheduler. Es asíncrono: solo dispara la exportación; los mensajes
 * llegan poco después por el webhook.
 */
class ReconcileSmsReceived extends Command
{
    protected $signature = 'sms:reconcile-received {--hours=24 : Ventana hacia atrás a re-exportar} {--device= : Device concreto (default: config)}';

    protected $description = 'Pide al gateway re-exportar los SMS entrantes recientes por si el webhook del teléfono no llegó';

    public function handle(SmsGatewayClient $client): int
    {
        $hours = max(1, (int) $this->option('hours'));

        $since    = now()->subHours($hours)->toIso8601String();
        $until    = now()->toIso8601String();
        $deviceId = $this->option('device') ?: config('sms.gateway.device_id');

        $res = $client->requestInboxExport($since, $until, $deviceId ?: null);

        if (! $res['ok']) {
            $this->error('El gateway rechazó la re-exportación de entrantes.');
            return self::FAILURE;
        }

        $this->info("Re-exportación de entrantes solicitada (últimas {$hours}h).");

        return self::SUCCESS;
    }
}
