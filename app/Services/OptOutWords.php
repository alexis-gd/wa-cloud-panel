<?php

namespace App\Services;

/**
 * Única fuente de verdad de las palabras que dan de baja a un contacto.
 *
 * Antes la lista vivía duplicada en `WebhookController` y en `SmsWebhookController`, cada
 * uno con su propia normalización: el de SMS ignoraba acentos y el de WhatsApp no. Al ser
 * una regla legal (LFPDPPP exige un medio de revocación) y de política de Meta, tiene que
 * ser la misma en los dos canales y cambiar en un solo lugar.
 *
 * **Por qué `NO` ya no está en la lista.** Fue un falso positivo grave en producción: un
 * contacto que ya había aceptado ("Me interesa") contestó `No` a la pregunta de un agente
 * ("¿ya cuenta con la aplicación?") y el sistema lo dio de baja permanente. `NO` es la
 * respuesta natural a cualquier pregunta de sí/no, así que como palabra de baja es una
 * trampa en cuanto existe conversación con agentes. `BAJA` y `CANCELAR` salieron por
 * decisión del cliente: la lista acordada es `STOP` y `DAR DE BAJA`.
 *
 * El cumplimiento no se debilita: quedan dos frases inequívocas, más el opt-out **nativo**
 * de WhatsApp, que llega como error `131050` y da de baja sin que el contacto escriba nada.
 */
class OptOutWords
{
    /** Palabras acordadas con el cliente. Aplican a WhatsApp y a SMS por igual. */
    public const WORDS = ['STOP', 'DAR DE BAJA'];

    /**
     * Palabras de baja que las operadoras de EEUU imponen por norma. Solo se revisan en el
     * canal SMS. Nadie las escribe por accidente en español, así que no arriesgan falsos
     * positivos y amplían el cumplimiento sin costo.
     */
    public const SMS_CARRIER_WORDS = ['STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT'];

    /**
     * ¿El mensaje completo es una petición de baja?
     *
     * Exige que el mensaje entero sea la frase, no que la contenga: "no quiero dar de baja
     * mi crédito" no da de baja. Es la misma regla estricta de siempre, solo que ahora
     * tolera acentos, mayúsculas, espacios de más y el punto final.
     */
    public static function matches(string $message, bool $includeCarrierWords = false): bool
    {
        $words = $includeCarrierWords
            ? array_merge(self::WORDS, self::SMS_CARRIER_WORDS)
            : self::WORDS;

        return in_array(self::normalize($message), $words, true);
    }

    /**
     * Mayúsculas, sin acentos, sin espacios repetidos y sin signos al final.
     * Así "Dar de baja." y "DAR  DE  BAJA" cuentan igual que "DAR DE BAJA".
     */
    public static function normalize(string $message): string
    {
        $upper = mb_strtoupper(trim($message), 'UTF-8');

        $sinAcentos = strtr($upper, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);

        $sinEspaciosDeMas = preg_replace('/\s+/u', ' ', $sinAcentos);

        return trim($sinEspaciosDeMas, " .,;:!¡?¿");
    }
}
