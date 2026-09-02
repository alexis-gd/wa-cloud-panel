<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Services\Tags\TagResolver;
use Illuminate\Support\Facades\DB;

/**
 * Da de alta en el panel los contactos que trae el API del cliente.
 *
 * Regla del cliente: **solo agregar nuevos**. Nunca se actualiza ni se reactiva a nadie que
 * ya exista, y menos a quien pidió su baja. Un contacto dado de baja que vuelva a aparecer en
 * el API se ignora: la baja manda sobre cualquier fuente externa (LFPDPPP y políticas de Meta).
 *
 * El mapeo de campos NO está en el código: vive en `config/contact_sync.php`, porque el API es
 * del cliente y sus nombres de campo pueden cambiar sin avisarnos. Se admiten varios nombres
 * por campo y gana el primero que traiga dato.
 */
class ContactSyncService
{
    /** Filas por lote en los insert masivos. */
    private const CHUNK = 500;

    /** Cuántos ejemplos de filas ilegibles se devuelven para diagnosticar. */
    private const MAX_SAMPLES = 5;

    public function __construct(private readonly ExternalContactsClient $client)
    {
    }

    /**
     * @param  bool  $dryRun  Si es true, NO escribe: solo reporta qué haría.
     * @return array<string, mixed>
     */
    public function sync(bool $dryRun = false): array
    {
        $resumen = [
            'ok'          => false,
            'error'       => null,
            'received'    => 0,
            'valid'       => 0,
            'invalid'     => 0,
            'duplicates'  => 0,
            'inserted'    => 0,
            'samples'     => [],
            'dry_run'     => $dryRun,
        ];

        $respuesta = $this->client->fetch();

        if (! $respuesta['ok']) {
            $resumen['error'] = $respuesta['error'];
            return $resumen;
        }

        $filas = $this->extraerFilas($respuesta['body']);

        if ($filas === null) {
            $resumen['error'] = 'No se encontró una lista de contactos en la respuesta. '
                . 'Revisa SYNC_API_ROOT en el .env (hoy: "' . config('contact_sync.root', '') . '").';
            return $resumen;
        }

        $resumen['ok']       = true;
        $resumen['received'] = count($filas);

        // ── Paso 1: leer y normalizar, sin tocar la BD ──
        $parsed = [];

        foreach ($filas as $fila) {
            $telefono = $this->valorDe($fila, config('contact_sync.field_phone'));
            $normal   = $telefono === null ? null : Contact::normalizePhone((string) $telefono);

            if ($normal === null) {
                $resumen['invalid']++;
                if (count($resumen['samples']) < self::MAX_SAMPLES) {
                    // La fila completa: sirve para ver si el campo se llama distinto.
                    $resumen['samples'][] = $fila;
                }
                continue;
            }

            // El mismo teléfono dos veces en la respuesta es uno solo.
            if (! isset($parsed[$normal])) {
                $nombre = $this->valorDe($fila, config('contact_sync.field_name'));
                $parsed[$normal] = $nombre !== null ? trim((string) $nombre) : null;
            }
        }

        $resumen['valid'] = count($parsed);

        if ($parsed === []) {
            return $resumen;
        }

        // ── Paso 2: descartar los que ya existen ──
        // `withTrashed()` es indispensable: `phone` es UNIQUE, así que un contacto borrado
        // sigue ocupando el número y sin esto el insert revienta por llave duplicada.
        $telefonos = array_keys($parsed);
        $existentes = [];

        foreach (array_chunk($telefonos, self::CHUNK) as $lote) {
            $existentes = array_merge(
                $existentes,
                Contact::withTrashed()->whereIn('phone', $lote)->pluck('phone')->all()
            );
        }

        $existentes = array_flip($existentes);
        $nuevos     = [];

        foreach ($parsed as $telefono => $nombre) {
            if (isset($existentes[$telefono])) {
                $resumen['duplicates']++;
                continue;
            }

            $nuevos[] = [
                'phone'      => $telefono,
                'name'       => $nombre ?: null,
                'status'     => 'active',
                'source'     => 'api',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($dryRun) {
            // En seco se reporta lo que se insertaría, pero no se escribe nada.
            $resumen['inserted'] = count($nuevos);
            return $resumen;
        }

        foreach (array_chunk($nuevos, self::CHUNK) as $lote) {
            Contact::insert($lote);
            $resumen['inserted'] += count($lote);
        }

        $this->etiquetar(array_column($nuevos, 'phone'));

        return $resumen;
    }

    /**
     * Etiqueta los contactos recién dados de alta, si hay etiqueta configurada. Deja segmentar
     * campañas por "los que vinieron del sistema del cliente".
     *
     * @param  string[]  $telefonos
     */
    private function etiquetar(array $telefonos): void
    {
        $nombre = config('contact_sync.tag');

        if (empty($nombre) || $telefonos === []) {
            return;
        }

        $tag = TagResolver::resolve([$nombre]);
        $id  = $tag['ids'][TagResolver::slug($nombre)] ?? null;

        if ($id === null) {
            return;
        }

        foreach (array_chunk($telefonos, self::CHUNK) as $lote) {
            $pares = Contact::whereIn('phone', $lote)
                ->pluck('id')
                ->map(fn (int $contactId) => ['contact_id' => $contactId, 'tag_id' => $id])
                ->all();

            if ($pares !== []) {
                DB::table('contact_tag')->insertOrIgnore($pares);
            }
        }
    }

    /**
     * Saca el arreglo de contactos de la respuesta, según `SYNC_API_ROOT`.
     * Devuelve null si ahí no hay una lista, para poder decirlo en vez de fallar en silencio.
     */
    private function extraerFilas(mixed $body): ?array
    {
        $root  = (string) config('contact_sync.root', '');
        $filas = $root === '' ? $body : data_get($body, $root);

        return is_array($filas) && array_is_list($filas) ? $filas : null;
    }

    /**
     * Primer valor con dato entre varios nombres de campo posibles, separados por coma.
     * Tolera mayúsculas distintas: "Telefono" y "telefono" son el mismo campo.
     */
    private function valorDe(mixed $fila, ?string $nombres): mixed
    {
        if (! is_array($fila) || empty($nombres)) {
            return null;
        }

        $porMinusculas = array_change_key_case($fila, CASE_LOWER);

        foreach (explode(',', $nombres) as $nombre) {
            $clave = strtolower(trim($nombre));
            $valor = $porMinusculas[$clave] ?? null;

            if ($valor !== null && $valor !== '') {
                return $valor;
            }
        }

        return null;
    }
}
