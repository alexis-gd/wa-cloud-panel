<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\MessageLog;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Vigila que el webhook SMS siga devolviendo eventos (entregas/respuestas). Si estamos
 * enviando pero no llega nada de vuelta, o si los eventos se rechazan por firma, crea una
 * alerta en la campana (AppNotification) para que el operador se entere sin tener que
 * revisar la pantalla de Configuración. Corre cada 15 min desde el scheduler.
 */
class MonitorSmsWebhook extends Command
{
    protected $signature = 'sms:monitor-webhook';

    protected $description = 'Alerta si el webhook SMS deja de devolver eventos';

    public function handle(): int
    {
        $lastHit      = $this->ts('sms_webhook_last_hit_at');
        $lastOk       = $this->ts('sms_webhook_last_at');
        $lastRejected = $this->ts('sms_webhook_last_rejected_at');

        // ¿Enviamos SMS en la última hora? Si no, no esperamos eventos y no alertamos.
        $recentSend = MessageLog::where('channel', 'sms')
            ->where('sent_at', '>=', now()->subHour())
            ->exists();

        $problem = null;

        // 1) Rechazo por firma: llegan eventos pero se rechazan (SMS_WEBHOOK_SECRET no coincide).
        if ($lastRejected && $lastRejected->gte(now()->subHour())
            && (! $lastOk || $lastRejected->gte($lastOk))) {
            $problem = 'El webhook SMS recibe eventos pero los rechaza por firma. Revisa que SMS_WEBHOOK_SECRET del panel coincida con la Signing Key del teléfono.';
        }
        // 2) Silencio con actividad: estamos enviando pero no llega nada de vuelta.
        elseif ($recentSend && (! $lastHit || $lastHit->lt(now()->subMinutes(45)))) {
            $problem = 'El webhook SMS no está devolviendo eventos (entregas y respuestas). Revisa el gateway y el teléfono.';
        }

        if (! $problem) {
            return self::SUCCESS;
        }

        // Dedup: no repetir la alerta si ya hay una sin leer en las últimas 3 horas.
        $exists = AppNotification::where('type', 'sms_webhook_down')
            ->whereNull('read_at')
            ->where('created_at', '>=', now()->subHours(3))
            ->exists();

        if (! $exists) {
            AppNotification::create([
                'type'  => 'sms_webhook_down',
                'title' => 'Webhook SMS sin respuesta',
                'body'  => $problem,
            ]);
            $this->warn('Alerta creada: ' . $problem);
        }

        return self::SUCCESS;
    }

    private function ts(string $key): ?Carbon
    {
        $v = Setting::get($key);
        return $v ? Carbon::parse($v) : null;
    }
}
