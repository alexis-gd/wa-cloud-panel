<?php

namespace App\Services\Otp;

/**
 * Arma el envio de una plantilla de Autenticacion.
 *
 * **El codigo va dos veces en el payload** - una en el cuerpo y otra en el boton - y asi lo
 * pide la doc de Meta ("this value must appear twice in the payload"). Mandarlo una sola vez
 * entrega el mensaje con el boton de copiar vacio.
 *
 * El `sub_type` es `url`, no `copy_code`, aunque la plantilla se creo con un boton de tipo
 * `OTP`: Meta lo convierte a URL al aprobarla. Es el error facil al copiar el ejemplo de una
 * plantilla de marketing con cupon.
 */
class OtpTemplate
{
    public static function payload(string $telefono, string $plantilla, string $idioma, string $codigo): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $telefono,
            'type'              => 'template',
            'template' => [
                'name'     => $plantilla,
                'language' => ['code' => $idioma],
                'components' => [
                    [
                        'type'       => 'body',
                        'parameters' => [['type' => 'text', 'text' => $codigo]],
                    ],
                    [
                        'type'       => 'button',
                        'sub_type'   => 'url',
                        'index'      => '0',
                        'parameters' => [['type' => 'text', 'text' => $codigo]],
                    ],
                ],
            ],
        ];
    }
}
