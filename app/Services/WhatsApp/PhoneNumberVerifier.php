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
            return [
                'ok'    => false,
                'error' => $res['body']['error']['message'] ?? 'Meta no respondió correctamente.',
                'code'  => $res['body']['error']['code'] ?? null,
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
}
