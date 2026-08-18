<?php

namespace App\Services;

/**
 * Etiquetas en español de los valores internos que se exportan a Excel.
 *
 * En la BD y en la API los estados viajan en inglés (`active`, `delivered`...) porque son
 * identificadores, no texto: renombrarlos rompería queries, índices y el frontend. Pero el
 * Excel lo abre el cliente, no un programador, así que ahí sí se traduce.
 *
 * El panel tiene sus propios diccionarios en cada vista de Vue. Aquí solo está lo que sale
 * por los archivos descargables.
 */
class StatusLabels
{
    private const CONTACT_STATUS = [
        'active'      => 'Activo',
        'opted_out'   => 'Baja',
        'invalid'     => 'Inválido',
        'unreachable' => 'Inalcanzable',
    ];

    private const CONTACT_SOURCE = [
        'excel'  => 'Excel',
        'manual' => 'Manual',
        'api'    => 'API',
    ];

    private const MESSAGE_STATUS = [
        'pending'   => 'Pendiente',
        'sent'      => 'Enviado',
        'delivered' => 'Entregado',
        'read'      => 'Leído',
        'failed'    => 'Fallido',
        'discarded' => 'Descartado',
    ];

    private const CHANNEL = [
        'whatsapp' => 'WhatsApp',
        'sms'      => 'SMS',
    ];

    public static function contactStatus(?string $value): string
    {
        return self::traducir(self::CONTACT_STATUS, $value);
    }

    public static function contactSource(?string $value): string
    {
        return self::traducir(self::CONTACT_SOURCE, $value);
    }

    public static function messageStatus(?string $value): string
    {
        return self::traducir(self::MESSAGE_STATUS, $value);
    }

    public static function channel(?string $value): string
    {
        return self::traducir(self::CHANNEL, $value);
    }

    /**
     * Un valor sin traducción se devuelve tal cual, no vacío: si algún día aparece un estado
     * nuevo, el Excel lo muestra crudo (raro pero legible) en vez de esconder el dato.
     */
    private static function traducir(array $mapa, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $mapa[$value] ?? $value;
    }
}
