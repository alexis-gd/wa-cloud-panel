<?php

namespace App\Services\WhatsApp;

use App\Models\MessageLog;

/**
 * Traduce a español por qué un mensaje no llegó.
 *
 * Fuente única: antes el texto amigable vivía solo en `WebhookController` (para la campanita)
 * y el detalle de campaña mostraba un guion, porque leía `error_message` - campo que solo se
 * llena cuando Meta rechaza AL DESPACHAR. Las fallas de ENTREGA (las que llegan por webhook,
 * como el 131049) guardan `delivery_error_code`, y nadie las traducía.
 */
class DeliveryReason
{
    /** Errores de entrega de Meta que llegan por webhook, en lenguaje del operador. */
    public const DELIVERY_ERRORS = [
        131049 => 'El destinatario alcanzó su límite de mensajes de marketing. No es un problema del número.',
        131050 => 'El destinatario se dio de baja de mensajes de marketing en WhatsApp.',
        131048 => 'Entrega pausada por límite de envíos. Se reanudará automáticamente.',
        131064 => 'Cuenta pausada por categorización de plantillas. Se reanudará automáticamente.',
        131026 => 'El mensaje no pudo ser entregado al destinatario.',
        131047 => 'Pasaron más de 24 horas desde el último mensaje del contacto.',
        368    => 'Cuenta temporalmente restringida por Meta.',
        132001 => 'La plantilla no está aprobada en Meta.',
        132007 => 'La plantilla infringe una política de WhatsApp.',
        132015 => 'La plantilla está pausada por baja calidad.',
        132016 => 'La plantilla se desactivó de forma permanente por baja calidad.',
    ];

    /** Por qué el sistema decidió NO enviar (nunca salió, no se cobra). */
    public const DISCARD_REASONS = [
        'cooldown'       => 'En enfriamiento: se le envió hace poco.',
        'snooze'         => 'Pospuesto: el contacto pidió que le escribieran después.',
        'opted_out'      => 'Dado de baja: pidió no recibir más mensajes.',
        'dedup_today'    => 'Ya recibió un mensaje hoy.',
        'unreachable'    => 'Inalcanzable: sus mensajes anteriores no llegaron.',
        'sms_blocked'    => 'Bloqueado para SMS.',
        'marketing_hold' => 'En espera: Meta pide 24 horas antes de volver a escribirle.',
    ];

    /** Texto corto para la columna de la tabla (el largo va en el tooltip). */
    public const SHORT_DISCARD = [
        'cooldown'       => 'Enfriamiento',
        'snooze'         => 'Pospuesto',
        'opted_out'      => 'Baja',
        'dedup_today'    => 'Ya enviado hoy',
        'unreachable'    => 'Inalcanzable',
        'sms_blocked'    => 'SMS bloqueado',
        'marketing_hold' => 'En espera (Meta)',
    ];

    /**
     * Motivo de un registro, listo para mostrar. null si el mensaje va bien.
     *
     * @return array{short: string, detail: string}|null
     */
    public static function forLog(MessageLog $log): ?array
    {
        if ($log->discard_reason) {
            return [
                'short'  => self::SHORT_DISCARD[$log->discard_reason] ?? $log->discard_reason,
                'detail' => self::DISCARD_REASONS[$log->discard_reason] ?? $log->discard_reason,
            ];
        }

        // Falla de ENTREGA: Meta aceptó el mensaje pero después avisó que no llegó.
        if ($log->delivery_error_code !== null) {
            $code   = (int) $log->delivery_error_code;
            $detail = self::DELIVERY_ERRORS[$code]
                ?? ($log->delivery_error_title ?: "Meta reportó el error {$code}.");

            return ['short' => $detail, 'detail' => "{$detail} (código {$code})"];
        }

        // Falla AL DESPACHAR: Meta rechazó la llamada. `error_message` trae el JSON crudo.
        if ($log->error_message) {
            $detail = self::fromRawError($log->error_message);

            return ['short' => $detail, 'detail' => $detail];
        }

        return null;
    }

    /**
     * Saca el mensaje legible del JSON que devuelve Meta (o el texto plano del gateway SMS).
     * Si viene un código conocido, gana el texto en español sobre el de Meta (que va en inglés).
     */
    private static function fromRawError(string $raw): string
    {
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return $raw; // El gateway SMS manda texto plano.
        }

        $code = $decoded['code'] ?? null;

        if ($code !== null && isset(self::DELIVERY_ERRORS[(int) $code])) {
            return self::DELIVERY_ERRORS[(int) $code];
        }

        return $decoded['message'] ?? $decoded['error'] ?? $raw;
    }
}
