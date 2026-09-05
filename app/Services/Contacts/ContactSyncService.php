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
            'excluded'    => 0,
            'refreshed'   => 0,   // a cuántos ya existentes se les actualizó el estado de cartera
            'by_status'   => [],   // cuántos vienen de cada estado del sistema del cliente
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

            // El estado en SU sistema (LIQUIDADO, BURÓ, BAJA...). Se cuenta siempre, aunque
            // luego se excluya: el operador necesita saber qué trae el API para decidir.
            $estado = $this->valorDe($fila, config('contact_sync.field_status'));
            $estado = $estado === null ? null : trim((string) $estado);

            if ($estado !== null && $estado !== '') {
                $resumen['by_status'][$estado] = ($resumen['by_status'][$estado] ?? 0) + 1;
            }

            // El filtro de estados decide si se le DA DE ALTA, no si se le actualiza. Un
            // excluido que YA existe igual necesita su estado al día: si el cliente excluye
            // BURÓ y alguien cae en buró, saltárselo lo dejaría marcado LIQUIDADO para
            // siempre - y entraría a la campaña de renovación. El API manda sobre el estado.
            $aceptado = $this->estadoAceptado($estado);

            if (! $aceptado) {
                $resumen['excluded']++;
            }

            // El mismo teléfono dos veces en la respuesta es uno solo.
            if (! isset($parsed[$normal])) {
                $nombre = $this->valorDe($fila, config('contact_sync.field_name'));
                $parsed[$normal] = [
                    'name'     => $nombre !== null ? trim((string) $nombre) : null,
                    'status'   => $estado ?: null,
                    'eligible' => $aceptado,
                ];
            }
        }

        ksort($resumen['by_status']);

        // "Válidos" son los que podrían darse de alta: los excluidos no cuentan aquí, aunque
        // sigan viajando en $parsed para poder refrescarles el estado si ya existen.
        $resumen['valid'] = count(array_filter($parsed, fn (array $d) => $d['eligible']));

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

        // Teléfonos que YA existen, agrupados por su estado de cartera actual. Se refresca
        // en bloque: una consulta por estado distinto, no una por contacto.
        $refrescar = [];

        foreach ($parsed as $telefono => $datos) {
            // Ya existe: no se da de alta, pero sí se le pone al día el estado de cartera.
            // Aplica también a los excluidos: el filtro es una puerta de entrada, no una
            // razón para dejar de reflejar lo que dice el API de alguien que ya está dentro.
            if (isset($existentes[$telefono])) {
                $resumen['duplicates']++;

                if ($datos['status']) {
                    $refrescar[$datos['status']][] = $telefono;
                }

                continue;
            }

            // No existe y su estado está excluido: no entra. Es lo único que hace el filtro.
            if (! $datos['eligible']) {
                continue;
            }

            $nuevos[] = [
                'phone'      => $telefono,
                'name'       => $datos['name'] ?: null,
                // `status` aquí es NUESTRO estado (activo / baja / inválido), que no tiene
                // nada que ver con el `Estado` de su cartera. Todo lo que entra, entra activo.
                'status'     => 'active',
                'source'     => 'api',
                // El estado de cartera va como columna del contacto, no como etiqueta: es
                // un dato del cliente que cambia con el tiempo, no una decisión del operador.
                'portfolio_status' => $datos['status'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($dryRun) {
            // En seco se reporta lo que se insertaría y a cuántos se les movería el estado,
            // pero no se escribe nada.
            $resumen['inserted']  = count($nuevos);
            $resumen['refreshed'] = $this->contarPorRefrescar($refrescar);
            return $resumen;
        }

        foreach (array_chunk($nuevos, self::CHUNK) as $lote) {
            Contact::insert($lote);
            $resumen['inserted'] += count($lote);
        }

        $resumen['refreshed'] = $this->refrescarEstado($refrescar);

        $this->etiquetar(array_column($nuevos, 'phone'));

        return $resumen;
    }

    /**
     * ¿Este estado del sistema del cliente pasa los filtros configurados?
     * Sin filtros configurados pasa todo: no se descarta a nadie en silencio.
     */
    private function estadoAceptado(?string $estado): bool
    {
        $excluir = $this->lista(config('contact_sync.status_exclude'));
        $incluir = $this->lista(config('contact_sync.status_include'));

        $normalizado = $estado === null ? null : mb_strtoupper($estado);

        // Excluir gana sobre incluir: es la regla más restrictiva y la que protege.
        if ($normalizado !== null && in_array($normalizado, $excluir, true)) {
            return false;
        }

        if ($incluir === []) {
            return true;
        }

        return $normalizado !== null && in_array($normalizado, $incluir, true);
    }

    /** @return string[] */
    private function lista(?string $csv): array
    {
        if (empty($csv)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $v) => mb_strtoupper(trim($v)),
            explode(',', $csv)
        )));
    }

    /**
     * Actualiza el estado de cartera de los contactos que YA existían.
     *
     * Es la única cosa que esta sincronización actualiza de un contacto existente, y es a
     * propósito: el estado cambia con el tiempo (quien hoy está en BURÓ mañana liquida) y
     * un dato congelado haría que el filtro mintiera más cada mes. El nombre, la baja y
     * cualquier otro campo siguen intocables: el panel manda sobre el API.
     *
     * Se puede apagar con `SYNC_REFRESH_STATUS=false` si el cliente prefiere congelar el
     * estado con el que entró cada quien.
     *
     * @param  array<string, string[]>  $porEstado  Estado de cartera => teléfonos con ese estado.
     * @return int  Cuántos contactos cambiaron de estado.
     */
    private function refrescarEstado(array $porEstado): int
    {
        if (! config('contact_sync.refresh_status', true) || $porEstado === []) {
            return 0;
        }

        $cambiados = 0;

        foreach ($porEstado as $estado => $telefonos) {
            foreach (array_chunk($telefonos, self::CHUNK) as $lote) {
                // `withTrashed`: un contacto borrado sigue ocupando el teléfono y su estado
                // de cartera también vale, por si el operador lo reactiva.
                // El `where` de desigualdad evita reescribir filas que ya estaban bien, así
                // el contador dice "cuántos CAMBIARON", no "cuántos vinieron".
                $cambiados += Contact::withTrashed()
                    ->whereIn('phone', $lote)
                    ->where(function ($q) use ($estado) {
                        $q->where('portfolio_status', '!=', $estado)
                          ->orWhereNull('portfolio_status');
                    })
                    ->update(['portfolio_status' => $estado]);
            }
        }

        return $cambiados;
    }

    /**
     * Cuántos contactos existentes cambiarían de estado, sin escribir. Solo para `--dry-run`.
     *
     * @param  array<string, string[]>  $porEstado
     */
    private function contarPorRefrescar(array $porEstado): int
    {
        if (! config('contact_sync.refresh_status', true) || $porEstado === []) {
            return 0;
        }

        $total = 0;

        foreach ($porEstado as $estado => $telefonos) {
            foreach (array_chunk($telefonos, self::CHUNK) as $lote) {
                $total += Contact::withTrashed()
                    ->whereIn('phone', $lote)
                    ->where(function ($q) use ($estado) {
                        $q->where('portfolio_status', '!=', $estado)
                          ->orWhereNull('portfolio_status');
                    })
                    ->count();
            }
        }

        return $total;
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
