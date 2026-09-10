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
        131026 => 'El número no tiene WhatsApp o no acepta mensajes. Se marcó como inválido y no se le vuelve a escribir.',
        130472 => 'Meta dejó a esta persona fuera de un experimento suyo y no le entregó el mensaje. No es un problema del número ni de la plantilla.',
        131047 => 'Pasaron más de 24 horas desde el último mensaje del contacto.',
        368    => 'Cuenta temporalmente restringida por Meta.',
        132001 => 'La plantilla no está aprobada en Meta.',
        132007 => 'La plantilla infringe una política de WhatsApp.',
        132015 => 'La plantilla está pausada por baja calidad.',
        132016 => 'La plantilla se desactivó de forma permanente por baja calidad.',
    ];

    /**
     * Motivos que manda el gateway SMS. Son los códigos de Android tal cual
     * (`RESULT_ERROR_NO_SERVICE`), en inglés y en mayúsculas: al operador le salían crudos.
     */
    public const SMS_ERRORS = [
        'RESULT_ERROR_GENERIC_FAILURE'    => 'El teléfono no pudo enviar el SMS.',
        'RESULT_ERROR_NO_SERVICE'         => 'El teléfono que envía los SMS se quedó sin señal.',
        'RESULT_ERROR_RADIO_OFF'          => 'El teléfono que envía los SMS tiene la señal apagada (modo avión).',
        'RESULT_ERROR_NULL_PDU'           => 'El teléfono rechazó el mensaje por un error interno.',
        'RESULT_ERROR_LIMIT_EXCEEDED'     => 'El teléfono alcanzó su tope de SMS por ahora.',
        'RESULT_ERROR_NO_DEFAULT_SMS_APP' => 'El teléfono no tiene configurada la app de SMS.',
        'RESULT_ERROR_SHORT_CODE_NOT_ALLOWED' => 'La compañía no permite enviar a ese número.',
        'RESULT_RIL_SMS_SEND_FAIL_RETRY'  => 'La compañía rechazó el SMS y el teléfono ya reintentó.',
        'RESULT_RIL_NETWORK_NOT_READY'    => 'La red de la compañía no estaba lista.',
        'RESULT_RIL_MODEM_ERR'            => 'Error del módem del teléfono que envía los SMS.',
        'RESULT_NETWORK_REJECT'           => 'La compañía rechazó el envío.',
        'RESULT_NO_MEMORY'                => 'El teléfono se quedó sin memoria para enviar.',
        'RESULT_NO_RESOURCES'             => 'El teléfono no tenía recursos para enviar en ese momento.',
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
     * `origin` dice QUIEN lo dijo, y de ahi sale el prefijo de `full`. Importa porque no es lo
     * mismo "nosotros decidimos no enviarlo" (enfriamiento, baja) que "el proveedor lo rechazo":
     * atribuirle a Meta una decision nuestra confunde al operador y lo manda a revisar la cuenta
     * de Meta cuando no hay nada que revisar.
     *
     * @return array{short: string, detail: string, origin: string, full: string}|null
     */
    public static function forLog(MessageLog $log): ?array
    {
        // Decision NUESTRA: el mensaje nunca salio. Sin prefijo de proveedor.
        if ($log->discard_reason) {
            $detail = self::DISCARD_REASONS[$log->discard_reason] ?? $log->discard_reason;

            return [
                'short'  => self::SHORT_DISCARD[$log->discard_reason] ?? $log->discard_reason,
                'detail' => $detail,
                'origin' => 'sistema',
                'full'   => $detail,
            ];
        }

        // Falla de ENTREGA: Meta aceptó el mensaje pero después avisó que no llegó.
        if ($log->delivery_error_code !== null) {
            $code   = (int) $log->delivery_error_code;
            $detail = self::DELIVERY_ERRORS[$code] ?? self::sinTraduccion($code);

            return self::deProveedor($log, $detail, "{$detail} (código {$code})");
        }

        // Falla AL DESPACHAR: Meta rechazó la llamada. `error_message` trae el JSON crudo.
        if ($log->error_message) {
            $detail = self::fromRawError($log->error_message);

            return self::deProveedor($log, $detail, $detail);
        }

        return null;
    }

    /**
     * Arma el motivo de una falla que reportó el proveedor, no nosotros.
     *
     * El proveedor depende del canal: WhatsApp es Meta, SMS es el gateway. Decir "Meta respondió"
     * en un SMS seria mentir - Meta ni se entera de ese canal.
     *
     * @return array{short: string, detail: string, origin: string, full: string}
     */
    private static function deProveedor(MessageLog $log, string $short, string $detail): array
    {
        $origin  = $log->channel === 'sms' ? 'gateway' : 'meta';
        $prefijo = $origin === 'gateway' ? 'El gateway de SMS respondió' : 'Meta respondió';

        return [
            'short'  => $short,
            'detail' => $detail,
            'origin' => $origin,
            'full'   => "{$prefijo}: {$detail}",
        ];
    }

    /**
     * Qué decirle al operador cuando Meta manda un código que no tenemos traducido.
     *
     * NUNCA se muestra el `delivery_error_title` de Meta: viene en inglés ("User's number is
     * part of an experiment") y el operador no tiene por qué leer eso. El título sigue guardado
     * en la base para soporte; lo que cambia es que no llega a la pantalla.
     *
     * El código sí se muestra: es lo único accionable, porque permite pedirlo a soporte.
     */
    /**
     * Motivo del gateway SMS que no tenemos mapeado.
     *
     * Si ya viene en español es texto nuestro (`SmsWebhookController`) y pasa tal cual. Si trae
     * pinta de código de Android (MAYUSCULAS_CON_GUION_BAJO) se envuelve, para no soltarle al
     * operador un `RESULT_RIL_SMS_SEND_FAIL` a secas.
     */
    private static function sinTraducirSms(string $raw): string
    {
        $limpio = trim($raw);

        if (preg_match('/^[A-Z][A-Z0-9_]{3,}$/', $limpio) !== 1) {
            return $limpio;
        }

        return "El teléfono no pudo enviar el SMS. Si se repite mucho, pásale el código {$limpio} a soporte.";
    }

    private static function sinTraduccion(int $code): string
    {
        return "Meta no entregó el mensaje y no dio un motivo que podamos explicar. "
            . "Si se repite mucho, pásale el código {$code} a soporte.";
    }

    /**
     * Saca el mensaje legible del JSON que devuelve Meta (o el texto plano del gateway SMS).
     *
     * El `message` de Meta viene en inglés, así que solo se usa el texto en español: con código
     * conocido, el nuestro; sin él, el genérico. El texto plano del gateway SMS sí pasa tal cual
     * porque ese ya viene en español y lo escribimos nosotros.
     */
    private static function fromRawError(string $raw): string
    {
        $decoded = json_decode($raw, true);

        // El gateway SMS manda texto plano, no JSON: o un código de Android o un texto nuestro.
        if (! is_array($decoded)) {
            return self::SMS_ERRORS[trim($raw)] ?? self::sinTraducirSms($raw);
        }

        $code = $decoded['code'] ?? null;

        if ($code === null) {
            return 'No se pudo enviar el mensaje. Si se repite, avisa a soporte.';
        }

        return self::DELIVERY_ERRORS[(int) $code] ?? self::sinTraduccion((int) $code);
    }
}
