<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Services\Tags\TagResolver;
use Illuminate\Support\Facades\DB;

/**
 * Importa filas de un Excel/CSV a la tabla de contactos, con etiquetado.
 *
 * El importador dejó de ser sobre todo un alta: las altas van a llegar por el cron que
 * consume el API externa, así que lo normal es que casi todas las filas del archivo sean
 * contactos que YA existen y que el operador está clasificando. Por eso una fila duplicada
 * con etiqueta NO se descarta: se le asigna la etiqueta igual y se reporta aparte.
 *
 * Nada se hace fila por fila: se parsea todo, se resuelven las etiquetas de una, se consulta
 * qué teléfonos ya existen por lotes y se insertan contactos y `contact_tag` en bloque. Con
 * un archivo grande esto es bastante más rápido que el `create()` por fila de antes.
 */
class ContactImporter
{
    /** Encabezados que se reconocen como columna de teléfono. */
    private const PHONE_HEADERS = ['telefono', 'teléfono', 'phone', 'número', 'numero', 'celular'];

    /** Encabezados que se reconocen como columna de nombre. */
    private const NAME_HEADERS = ['nombre', 'name', 'contacto'];

    /** Encabezados que se reconocen como columna de etiqueta. */
    private const TAG_HEADERS = ['etiqueta', 'etiquetas', 'tag', 'tags'];

    /** Filas por lote en los insert masivos. */
    private const CHUNK = 1000;

    /** Máximo de errores de formato que se devuelven al operador (el resto solo se cuenta). */
    private const MAX_ERRORS_LISTED = 10;

    /**
     * @param  array<int, array<int, mixed>>  $rows  Filas crudas de la hoja.
     * @return array<string, mixed>  Resumen para el panel.
     */
    public function import(array $rows): array
    {
        [$hasHeader, $phoneCol, $nameCol, $tagCol] = $this->detectColumns($rows);

        $dataRows = $hasHeader ? array_slice($rows, 1) : $rows;

        $summary = [
            'total'             => 0,
            'inserted'          => 0,
            'duplicates'        => 0,
            'invalid'           => 0,
            'errors'            => [],
            'tags_created'      => 0,
            'tags_assigned'     => 0,
            'duplicates_tagged' => 0,
            'has_tag_column'    => $tagCol !== null,
        ];

        // ── Paso 1: parsear el archivo entero, sin tocar la BD ──
        $parsed = [];   // phone normalizado => ['name' => ?string, 'tags' => string[]]

        foreach ($dataRows as $rowIndex => $row) {
            $rawPhone = trim((string) ($row[$phoneCol] ?? ''));

            if ($rawPhone === '') {
                continue;   // fila vacía
            }

            $summary['total']++;

            $normalized = Contact::normalizePhone($rawPhone);

            if ($normalized === null) {
                $summary['invalid']++;
                if (count($summary['errors']) < self::MAX_ERRORS_LISTED) {
                    $line = $rowIndex + ($hasHeader ? 2 : 1);
                    $summary['errors'][] = "Fila {$line}: '{$rawPhone}' no es un número válido";
                }
                continue;
            }

            $tags = $tagCol === null
                ? []
                : TagResolver::splitNames((string) ($row[$tagCol] ?? ''));

            if (isset($parsed[$normalized])) {
                // El mismo número dos veces en el archivo: sigue contando como duplicado,
                // pero se acumulan sus etiquetas en vez de perder las de la segunda fila.
                $summary['duplicates']++;
                $parsed[$normalized]['tags'] = array_merge($parsed[$normalized]['tags'], $tags);
                continue;
            }

            $parsed[$normalized] = [
                'name' => trim((string) ($row[$nameCol] ?? '')) ?: null,
                'tags' => $tags,
            ];
        }

        if ($parsed === []) {
            return $summary;
        }

        // ── Paso 2: resolver las etiquetas del archivo de una sola vez ──
        $allTagNames = [];
        foreach ($parsed as $data) {
            foreach ($data['tags'] as $name) {
                $allTagNames[] = $name;
            }
        }

        $tagMap = TagResolver::resolve($allTagNames);
        $summary['tags_created'] = $tagMap['created'];

        // ── Paso 3: separar los que ya existen de los nuevos ──
        $phones   = array_keys($parsed);
        $existing = $this->existingContacts($phones);

        $newRows = [];
        foreach ($parsed as $phone => $data) {
            if (isset($existing[$phone])) {
                $summary['duplicates']++;
                continue;
            }

            $newRows[] = [
                'phone'      => $phone,
                'name'       => $data['name'],
                'status'     => 'active',
                'source'     => 'excel',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($newRows, self::CHUNK) as $chunk) {
            Contact::insert($chunk);
            $summary['inserted'] += count($chunk);
        }

        // ── Paso 4: etiquetar (nuevos y existentes por igual) ──
        if ($tagCol !== null) {
            $ids = $this->contactIds($phones);
            $summary = array_merge($summary, $this->attachTags($parsed, $ids, $existing, $tagMap['ids']));
        }

        return $summary;
    }

    /** @return array{0: bool, 1: int, 2: int, 3: int|null} [hayEncabezado, colTel, colNombre, colEtiqueta] */
    private function detectColumns(array $rows): array
    {
        $firstRow = array_map('mb_strtolower', array_map('trim', array_map('strval', $rows[0] ?? [])));

        $hasHeader = (bool) array_intersect($firstRow, self::PHONE_HEADERS);

        $phoneCol = 0;
        $nameCol  = 1;
        $tagCol   = null;

        if ($hasHeader) {
            foreach ($firstRow as $i => $header) {
                if (in_array($header, self::PHONE_HEADERS, true)) {
                    $phoneCol = $i;
                }
                if (in_array($header, self::NAME_HEADERS, true)) {
                    $nameCol = $i;
                }
                if (in_array($header, self::TAG_HEADERS, true)) {
                    $tagCol = $i;
                }
            }
        }

        return [$hasHeader, $phoneCol, $nameCol, $tagCol];
    }

    /**
     * Teléfonos que ya existen, incluidos los borrados. `withTrashed()` es indispensable:
     * `phone` tiene índice UNIQUE, así que un contacto borrado sigue ocupando el número y
     * sin esto el insert revienta con llave duplicada y tumba la importación entera.
     *
     * @param  string[]  $phones
     * @return array<string, bool>  teléfono => estáBorrado
     */
    private function existingContacts(array $phones): array
    {
        $found = [];

        foreach (array_chunk($phones, self::CHUNK) as $chunk) {
            Contact::withTrashed()
                ->whereIn('phone', $chunk)
                ->get(['phone', 'deleted_at'])
                ->each(function ($c) use (&$found) {
                    $found[$c->phone] = $c->deleted_at !== null;
                });
        }

        return $found;
    }

    /**
     * @param  string[]  $phones
     * @return array<string, int>  teléfono => id (solo los NO borrados)
     */
    private function contactIds(array $phones): array
    {
        $ids = [];

        foreach (array_chunk($phones, self::CHUNK) as $chunk) {
            $ids += Contact::whereIn('phone', $chunk)->pluck('id', 'phone')->all();
        }

        return $ids;
    }

    /**
     * Inserta la relación contacto-etiqueta en bloque. `insertOrIgnore` evita reventar por la
     * llave primaria compuesta cuando el contacto ya tenía esa etiqueta.
     *
     * @return array{tags_assigned: int, duplicates_tagged: int}
     */
    private function attachTags(array $parsed, array $ids, array $existing, array $tagIds): array
    {
        $pairs            = [];
        $duplicatesTagged = 0;

        foreach ($parsed as $phone => $data) {
            if ($data['tags'] === [] || ! isset($ids[$phone])) {
                continue;   // sin etiqueta, o contacto borrado (no se re-etiqueta un borrado)
            }

            $asignadas = 0;

            foreach (array_unique($data['tags']) as $name) {
                $tagId = $tagIds[TagResolver::slug($name)] ?? null;
                if ($tagId === null) {
                    continue;
                }

                $pairs[] = ['contact_id' => $ids[$phone], 'tag_id' => $tagId];
                $asignadas++;
            }

            // Un contacto que ya existía y recibió etiqueta: es el caso de uso principal
            // del importador ahora, así que se reporta por separado.
            if ($asignadas > 0 && isset($existing[$phone])) {
                $duplicatesTagged++;
            }
        }

        $assigned = 0;

        foreach (array_chunk($pairs, self::CHUNK) as $chunk) {
            $assigned += DB::table('contact_tag')->insertOrIgnore($chunk);
        }

        return [
            'tags_assigned'     => $assigned,
            'duplicates_tagged' => $duplicatesTagged,
        ];
    }
}
