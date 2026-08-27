<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Resuelve el tamaño de página que pide el operador desde el selector "Mostrar".
 *
 * El selector ofrece 10 / 20 / 50 / 100 / 250 / 500 / Todos. "Todos" no puede ser
 * literalmente todos: con 200,000 contactos el JSON pesa decenas de MB y el navegador
 * se cuelga al renderizar. Por eso "Todos" se traduce a un tope duro (ALL_CAP) y la
 * respuesta avisa cuando hubo recorte, para que el operador afine el filtro o exporte.
 */
class PageSize
{
    /** Tope duro cuando el operador elige "Todos". */
    public const ALL_CAP = 5000;

    /** Valores que el selector puede mandar (además de "all"). */
    public const OPTIONS = [10, 20, 50, 100, 250, 500];

    /**
     * Devuelve cuántos registros traer. Cualquier valor fuera del catálogo cae al default,
     * así un `per_page=999999` a mano no tumba el servidor.
     */
    public static function from(Request $request, int $default): int
    {
        $raw = $request->input('per_page');

        if ($raw === null || $raw === '') {
            return $default;
        }

        if (self::isAll($raw)) {
            return self::ALL_CAP;
        }

        $value = (int) $raw;

        return in_array($value, self::OPTIONS, true) ? $value : $default;
    }

    /** ¿El operador eligió "Todos"? */
    public static function isAll(mixed $raw): bool
    {
        return is_string($raw) && strtolower($raw) === 'all';
    }

    /**
     * ¿La respuesta quedó recortada por el tope de "Todos"?
     * El front lo usa para mostrar el aviso "Mostrando 5,000 de N".
     */
    public static function wasCapped(Request $request, int $total): bool
    {
        return self::isAll($request->input('per_page')) && $total > self::ALL_CAP;
    }
}
