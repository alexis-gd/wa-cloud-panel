<?php

namespace App\Services\WhatsApp;

class PhoneNumberVerifier
{
    public function __construct(private readonly WhatsAppClient $client) {}

    /**
     * Verifica un número contra Meta consultando su estado en la Graph API.
     * Devuelve solo campos seguros (nunca el token ni IDs internos crudos).
     *
     * @return array{ok:bool, data?:array, error?:string, code?:int|null}
     */
    public function verify(string $phoneNumberId, string $token): array
    {
        $res = $this->client->get($phoneNumberId, $token, [
            'fields' => 'display_phone_number,verified_name,code_verification_status,name_status,quality_rating,platform_type',
        ]);

        if (! $res['ok']) {
            $code = $res['body']['error']['code'] ?? null;

            return [
                'ok'       => false,
                'error'    => $res['body']['error']['message'] ?? 'Meta no respondió correctamente.', // crudo, para log
                'code'     => $code,
                'friendly' => $this->friendlyMessage($code), // para mostrar al usuario
            ];
        }

        $b = $res['body'];

        return [
            'ok'   => true,
            'data' => [
                'display_phone_number'     => $b['display_phone_number']     ?? null,
                'verified_name'            => $b['verified_name']            ?? null,
                'code_verification_status' => $b['code_verification_status'] ?? null,
                'name_status'              => $b['name_status']              ?? null,
                'quality_rating'           => $b['quality_rating']           ?? null,
            ],
        ];
    }

    /** Traduce el código de error de Meta a un mensaje claro para el operador. */
    private function friendlyMessage(?int $code): string
    {
        return match ($code) {
            100          => 'El Phone number ID no existe en esta cuenta de Meta, o el token no tiene permiso sobre él. Revisa que el ID sea correcto.',
            0, 190, 200  => 'El token de la cuenta no es válido o expiró. Actualízalo arriba, en "Token de acceso WhatsApp".',
            3, 10        => 'El token de la cuenta no tiene permisos para este número.',
            368, 131031  => 'La cuenta de WhatsApp está restringida por Meta. Revisa el Business Manager.',
            33           => 'Ese número fue eliminado en Meta.',
            default      => 'No se pudo verificar el número con Meta. Revisa el Phone number ID y que el token de la cuenta sea válido.',
        };
    }
}
