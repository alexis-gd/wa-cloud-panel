<?php

namespace App\Services\Contacts;

use App\Models\MessageLog;
use Illuminate\Support\Carbon;

/**
 * Resuelve "a quién contactamos" en una fecha o rango, para el API que consume un sistema
 * externo del cliente.
 *
 * Criterio de "contactado": el mensaje SALIÓ (`sent`, `delivered` o `read`). Es el mismo
 * criterio con el que el sistema calcula el enfriamiento y el dedup, así que el número que
 * devuelve este API cuadra con lo que el operador ve en el panel. Un mensaje fallido o
 * descartado no cuenta: a esa persona no se le contactó.
 *
 * Cuenta los DOS canales (WhatsApp y SMS) y devuelve UNA fila por contacto: a quien recibió
 * tres mensajes ese día se le contactó una vez, no tres.
 */
class ContactedLookup
{
    /** Estados de message_log que cuentan como contacto real. */
    private const SENT_STATUSES = ['sent', 'delivered', 'read'];

    /** Zona en la que el cliente piensa las fechas. Un día es de 00:00 a 23:59 CST. */
    private const TZ = 'America/Mexico_City';

    /** Tope de filas por página. Un rango amplio puede tocar decenas de miles de contactos. */
    public const MAX_PER_PAGE = 5000;

    public const DEFAULT_PER_PAGE = 1000;

    /**
     * Contactos alcanzados entre dos fechas (inclusive las dos).
     *
     * @param  string  $desde  Fecha 'Y-m-d' tal como la mandó el sistema externo (día CST).
     * @param  string  $hasta  Fecha 'Y-m-d' inclusive.
     * @return array{data: array<int, array{name: ?string, phone: string}>, total: int}
     */
    public static function between(string $desde, string $hasta, int $page, int $perPage): array
    {
        // Las fechas entran como TEXTO 'Y-m-d' a propósito. Con objetos Carbon el día se
        // corría: `createFromFormat('Y-m-d', ...)` conserva la hora actual, y al convertir de
        // UTC a CST un pedido de las 02:00 UTC caía en el día anterior. Interpretando el texto
        // directo en CST, "17 de agosto" es el 17 de agosto y punto.
        $from = Carbon::createFromFormat('Y-m-d', $desde, self::TZ)->startOfDay()->utc();
        $to   = Carbon::createFromFormat('Y-m-d', $hasta, self::TZ)->endOfDay()->utc();

        // Un contacto por fila: el DISTINCT lo resuelve MySQL sobre el índice, en vez de
        // traer una fila por mensaje y deduplicar en PHP (un rango amplio son millones).
        $base = MessageLog::query()
            ->whereIn('status', self::SENT_STATUSES)
            ->whereBetween('sent_at', [$from, $to])
            ->select('to_number')
            ->distinct();

        $total = (clone $base)->count('to_number');

        // El orden por teléfono es lo que hace que paginar sea estable: sin un orden fijo,
        // dos páginas pueden repetir o saltarse contactos.
        $phones = (clone $base)
            ->orderBy('to_number')
            ->forPage($page, $perPage)
            ->pluck('to_number');

        // El nombre sale de contactos. Un teléfono sin contacto (borrado a mano) igual se
        // devuelve: se le contactó, y ocultarlo daría un total que no cuadra.
        $names = \App\Models\Contact::withTrashed()
            ->whereIn('phone', $phones)
            ->pluck('name', 'phone');

        $data = $phones->map(fn (string $phone) => [
            'name'  => $names[$phone] ?? null,
            'phone' => $phone,
        ])->values()->all();

        return ['data' => $data, 'total' => $total];
    }

    /** Normaliza el tamaño de página que pide el sistema externo. */
    public static function perPage(mixed $raw): int
    {
        $value = (int) $raw;

        if ($value < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($value, self::MAX_PER_PAGE);
    }
}
