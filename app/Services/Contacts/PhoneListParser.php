<?php

namespace App\Services\Contacts;

use App\Models\Contact;

/**
 * Convierte un pegado del operador (una columna de Excel, una lista suelta) en teléfonos
 * normalizados listos para filtrar.
 *
 * El cliente copia una columna de Excel y la pega en el buscador de Contactos: llegan 500
 * números separados por saltos de línea, tabuladores, comas, punto y coma o espacios, a
 * veces con paréntesis, guiones o el +.
 *
 * El espacio es ambiguo: separa números ("529231111111 529232222222") pero también aparece
 * DENTRO de uno ("52 923 111 1111", como lo formatea Excel). Por eso se parsea dos veces -
 * una tratando el espacio como separador y otra solo con saltos de línea - y gana el
 * parseo que rescata más números. Con listas normales las dos dan igual; con números
 * formateados solo la segunda funciona, y al revés con una lista en una sola línea.
 */
class PhoneListParser
{
    /**
     * Tope de números por pegado. Arriba de esto el IN de MySQL y el propio parseo dejan
     * de ser gratis, y ninguna pantalla muestra tanto de forma útil.
     */
    public const MAX_PHONES = 5000;

    /** Cuántos números no encontrados se devuelven al front (el resto solo se cuenta). */
    public const MAX_MISSING_LISTED = 500;

    /** Separadores "duros": nunca aparecen dentro de un número. */
    private const HARD_SEPARATORS = '/[\r\n\t,;|]+/u';

    /** Separadores duros + espacio. */
    private const SOFT_SEPARATORS = '/[\s,;|]+/u';

    /**
     * @return array{phones: string[], pasted: int, valid: int, invalid: int, invalid_samples: string[], truncated: bool}
     */
    public static function parse(string $raw): array
    {
        $bySpace = self::parseWith($raw, self::SOFT_SEPARATORS);
        $byLine  = self::parseWith($raw, self::HARD_SEPARATORS);

        // Empate -> gana el corte por espacios, que es como el cliente pega normalmente.
        return $byLine['valid'] > $bySpace['valid'] ? $byLine : $bySpace;
    }

    /** @return array{phones: string[], pasted: int, valid: int, invalid: int, invalid_samples: string[], truncated: bool} */
    private static function parseWith(string $raw, string $separators): array
    {
        $tokens = preg_split($separators, trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $phones         = [];
        $invalid        = 0;
        $invalidSamples = [];

        foreach ($tokens as $token) {
            $normalized = Contact::normalizePhone($token);

            if ($normalized === null) {
                $invalid++;
                if (count($invalidSamples) < 10) {
                    $invalidSamples[] = trim($token);
                }
                continue;
            }

            $phones[$normalized] = true;
        }

        $phones    = array_keys($phones);
        $truncated = count($phones) > self::MAX_PHONES;

        if ($truncated) {
            $phones = array_slice($phones, 0, self::MAX_PHONES);
        }

        return [
            'phones'          => $phones,
            'pasted'          => count($tokens),
            'valid'           => count($phones),
            'invalid'         => $invalid,
            'invalid_samples' => $invalidSamples,
            'truncated'       => $truncated,
        ];
    }
}
