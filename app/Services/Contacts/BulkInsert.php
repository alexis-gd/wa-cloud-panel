<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Support\Facades\Log;

/**
 * Mete contactos en lote sin que una fila mala tire la carga entera.
 *
 * `Contact::insert()` manda un solo INSERT con cientos de filas: si **una** no cabe en su
 * columna, MySQL rechaza **todo el lote**. Con `contacts.name` en `varchar(100)`, un nombre
 * largo del API del cliente o de un Excel bastaba para que no entrara nadie, con una excepcion
 * cruda en pantalla y ningun dato de cual fila fue.
 *
 * Aqui se hacen dos cosas:
 *
 * 1. **Prevenir**: el nombre se recorta a lo que cabe antes de intentar. Un nombre recortado es
 *    mejor que un contacto que no existe.
 * 2. **Aislar**: si aun asi el lote falla, se reintenta fila por fila. Las buenas entran y las
 *    malas se devuelven con su telefono, para poder decirle al cliente cuales fueron.
 *
 * El reintento fila por fila es lento a proposito: solo ocurre cuando ya hubo un error, y mas
 * vale tardar en el caso raro que perder la carga en el comun.
 */
class BulkInsert
{
    /** Lo que aguanta `contacts.name`. Si la columna cambia, cambia aqui. */
    public const MAX_NOMBRE = 100;

    /**
     * @param  array<int, array<string, mixed>>  $filas
     * @return array{inserted: int, rejected: array<int, array{phone: string, error: string}>}
     */
    public static function insertar(array $filas): array
    {
        $filas = array_map(self::recortar(...), $filas);

        try {
            Contact::insert($filas);

            return ['inserted' => count($filas), 'rejected' => []];
        } catch (\Throwable $e) {
            Log::warning('Insercion de contactos en lote rechazada, se reintenta fila por fila', [
                'filas' => count($filas),
                'error' => $e->getMessage(),
            ]);
        }

        return self::unaPorUna($filas);
    }

    /**
     * @param  array<int, array<string, mixed>>  $filas
     * @return array{inserted: int, rejected: array<int, array{phone: string, error: string}>}
     */
    private static function unaPorUna(array $filas): array
    {
        $entraron  = 0;
        $rechazadas = [];

        foreach ($filas as $fila) {
            try {
                Contact::insert([$fila]);
                $entraron++;
            } catch (\Throwable $e) {
                $rechazadas[] = [
                    'phone' => (string) ($fila['phone'] ?? '?'),
                    'error' => self::motivo($e),
                ];
            }
        }

        if ($rechazadas) {
            Log::error('Contactos que no se pudieron dar de alta', [
                'cuantos'  => count($rechazadas),
                // Solo los primeros: si vienen 5,000 malos, el log no es el lugar para listarlos.
                'ejemplos' => array_slice($rechazadas, 0, 5),
            ]);
        }

        return ['inserted' => $entraron, 'rejected' => $rechazadas];
    }

    /** @param array<string, mixed> $fila */
    private static function recortar(array $fila): array
    {
        if (isset($fila['name']) && is_string($fila['name'])) {
            $fila['name'] = mb_substr($fila['name'], 0, self::MAX_NOMBRE);
        }

        return $fila;
    }

    /**
     * El mensaje de MySQL completo trae la consulta entera con todos los telefonos del lote.
     * Eso no sirve para diagnosticar y si expone datos de contactos en un log.
     */
    private static function motivo(\Throwable $e): string
    {
        $mensaje = $e->getMessage();

        return mb_substr(strtok($mensaje, "\n") ?: 'Error al insertar', 0, 200);
    }
}
