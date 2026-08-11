<?php

namespace App\Services\WhatsApp;

use App\Models\Contact;
use App\Models\Setting;

/**
 * Sustituye marcadores por datos del contacto en las variables de la plantilla.
 *
 * Las variables de la campaña (`campaigns.body_vars`) son un valor fijo para toda la campaña.
 * Con `{nombre}` el operador pide que ese valor se resuelva **por contacto** en el momento del
 * envío, así una misma campaña saluda a cada quien por su nombre.
 *
 * Reglas que impone Meta y que aquí se respetan:
 *  - Una variable **nunca** puede ir vacía ni con solo espacios: el mensaje se rechaza. Por eso
 *    siempre hay un respaldo cuando el contacto no tiene nombre.
 *  - Las variables no admiten saltos de línea ni tabuladores.
 */
class MessagePersonalizer
{
    /** Marcador que el operador inserta en el valor de la variable. */
    public const NAME_TOKEN = '{nombre}';

    /** Palabra que se usa cuando el contacto no tiene nombre en la base. */
    private const FALLBACK_SETTING = 'personalization_fallback';
    private const FALLBACK_DEFAULT = 'cliente';

    /**
     * @param  array<int, string>  $bodyVars
     * @return array<int, string>
     */
    public function resolve(array $bodyVars, Contact $contact): array
    {
        if (empty($bodyVars)) {
            return $bodyVars;
        }

        $name = $this->firstName($contact);

        return array_map(
            fn ($value) => $this->clean(str_ireplace(self::NAME_TOKEN, $name, (string) $value)),
            $bodyVars,
        );
    }

    /** True si alguna variable pide personalización (sirve para avisar en la UI y en tests). */
    public function usesPersonalization(array $bodyVars): bool
    {
        foreach ($bodyVars as $value) {
            if (stripos((string) $value, self::NAME_TOKEN) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Primer nombre, capitalizado. Las bases del cliente vienen de Excel: nombres completos, en
     * mayúsculas o con espacios de sobra. "JUAN PEREZ GARCIA" se manda como "Juan", que es lo
     * que suena natural en un saludo.
     */
    private function firstName(Contact $contact): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $contact->name));

        if ($name === '') {
            return $this->fallback();
        }

        $first = explode(' ', $name)[0];

        // Un "nombre" que en realidad es un número o basura no sirve para saludar.
        if (mb_strlen($first) < 2 || preg_match('/^\p{L}/u', $first) !== 1) {
            return $this->fallback();
        }

        return mb_convert_case(mb_strtolower($first, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    private function fallback(): string
    {
        $value = trim((string) Setting::get(self::FALLBACK_SETTING, self::FALLBACK_DEFAULT));

        return $value !== '' ? $value : self::FALLBACK_DEFAULT;
    }

    /** Meta rechaza variables con saltos de línea, tabuladores o vacías. */
    private function clean(string $value): string
    {
        $value = trim(preg_replace('/[\r\n\t]+/', ' ', $value));

        return $value !== '' ? $value : $this->fallback();
    }
}
