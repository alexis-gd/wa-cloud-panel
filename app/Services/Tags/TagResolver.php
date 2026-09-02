<?php

namespace App\Services\Tags;

use App\Models\Tag;
use Illuminate\Support\Str;

/**
 * Convierte nombres de etiqueta escritos a mano (en una celda de Excel) en ids de la tabla
 * `tags`, creando las que falten.
 *
 * La llave real es el **slug**, no el nombre: "VIP", "vip" y "Vip" son la misma etiqueta.
 * Si se comparara por nombre, cada variante de mayúsculas crearía una etiqueta distinta y el
 * catálogo se llenaría de duplicados que el operador no puede distinguir de un vistazo.
 */
class TagResolver
{
    /** Separadores admitidos dentro de una celda con varias etiquetas. */
    private const SEPARATORS = '/[,;|]+/u';

    /** Largo máximo de `tags.name` (ver migración). */
    private const MAX_NAME = 100;

    /**
     * Parte el contenido de una celda en nombres de etiqueta limpios.
     *
     * @return string[]
     */
    public static function splitNames(string $cell): array
    {
        $parts = preg_split(self::SEPARATORS, $cell, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $names = [];

        foreach ($parts as $part) {
            $name = trim(preg_replace('/\s+/u', ' ', $part));

            // Sin nombre, o un nombre que no deja slug (solo signos): no es una etiqueta.
            if ($name === '' || self::slug($name) === '') {
                continue;
            }

            $names[] = mb_substr($name, 0, self::MAX_NAME);
        }

        return $names;
    }

    public static function slug(string $name): string
    {
        return Str::slug($name);
    }

    /**
     * Devuelve el mapa slug => id de todas las etiquetas pedidas, creando las que no existan.
     * Una sola consulta para buscar y un solo insert para crear, sin importar cuántas vengan.
     *
     * @param  string[]  $names
     * @return array{ids: array<string, int>, created: int}
     */
    public static function resolve(array $names): array
    {
        $wanted = [];   // slug => nombre tal como lo escribió el operador (gana el primero)

        foreach ($names as $name) {
            $slug = self::slug($name);
            if ($slug !== '' && ! isset($wanted[$slug])) {
                $wanted[$slug] = $name;
            }
        }

        if ($wanted === []) {
            return ['ids' => [], 'created' => 0];
        }

        $ids = Tag::whereIn('slug', array_keys($wanted))->pluck('id', 'slug')->all();

        $missing = array_diff_key($wanted, $ids);

        if ($missing === []) {
            return ['ids' => $ids, 'created' => 0];
        }

        $now = now();
        $rows = [];

        foreach ($missing as $slug => $name) {
            $rows[] = [
                'name'       => $name,
                'slug'       => $slug,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // insertOrIgnore por si dos importaciones simultáneas crean la misma etiqueta:
        // `name` y `slug` son UNIQUE y un insert normal reventaría la importación entera.
        Tag::insertOrIgnore($rows);

        $ids = Tag::whereIn('slug', array_keys($wanted))->pluck('id', 'slug')->all();

        return ['ids' => $ids, 'created' => count($missing)];
    }
}
