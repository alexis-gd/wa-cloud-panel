<?php

namespace App\Services\Contacts;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Traduce el filtro "Entregabilidad" de la pantalla de Contactos a SQL.
 *
 * La columna Entregabilidad se calcula hoy DESPUÉS de paginar (2 queries agregadas por
 * página, ver DeliverabilityBadges). Eso sirve para pintar, no para filtrar: filtrar obliga
 * a preguntarle a message_log por todos los contactos, así que aquí se arma con EXISTS
 * sobre el índice (to_number, channel, status, sent_at).
 *
 * El filtro es ACUMULATIVO: el operador puede elegir varios estados y se suman (OR). Elegir
 * "Enfriamiento - WhatsApp" y "Enviado hoy - WhatsApp" trae los dos grupos. Se puede mezclar
 * WhatsApp y SMS: "Disponible - WhatsApp" + "Disponible - SMS" trae a quien esté disponible
 * en cualquiera de los dos canales.
 *
 * Las reglas son espejo de las etiquetas de la tabla, con la misma precedencia. Si no,
 * el operador filtra "Enfriamiento" y le salen filas rotuladas "Enviado hoy":
 *   WhatsApp: No recibe > Pospuesto > En espera (Meta) > Enviado hoy > Enfriamiento > Disponible
 *   SMS:      No recibe > Enviado hoy > Enfriamiento > Disponible
 *
 * El eje SMS no mira el snooze: el snooze es por canal, solo WhatsApp (ver contexto-sms.md).
 */
class DeliverabilityFilter
{
    /** Estados de message_log que cuentan como "le llegó" (mismo criterio que los jobs). */
    private const SENT_STATUSES = ['sent', 'delivered', 'read'];

    /** Estados del contacto que bloquean WhatsApp. */
    private const WA_BLOCKED_STATUSES = ['opted_out', 'invalid', 'unreachable'];

    /** Valores que acepta el parámetro `deliverability`. */
    public const OPTIONS = [
        'wa_available', 'wa_sent_today', 'wa_cooldown', 'wa_snoozed', 'wa_hold', 'wa_blocked',
        'sms_available', 'sms_sent_today', 'sms_cooldown', 'sms_blocked',
    ];

    public static function isValid(mixed $option): bool
    {
        return is_string($option) && in_array($option, self::OPTIONS, true);
    }

    /**
     * Normaliza lo que llegó por request a una lista de opciones válidas.
     * Acepta un string suelto, una lista separada por comas (así viaja en el query string)
     * o un array (así viaja en el body del POST). Lo desconocido se descarta.
     *
     * @return string[]
     */
    public static function normalize(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }

        if (! is_array($raw)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($option) => is_string($option) ? trim($option) : $option, $raw),
            [self::class, 'isValid']
        )));
    }

    /**
     * Aplica el filtro al query de contactos. Varias opciones se suman (OR); una lista
     * vacía no filtra nada.
     *
     * @param  string[]  $options
     */
    public static function apply(Builder $query, array $options): Builder
    {
        if ($options === []) {
            return $query;
        }

        // Todo va dentro de un where() envolvente: sin él, el primer orWhere se saldría del
        // paréntesis y anularía los demás filtros (estado, tag, lista pegada).
        return $query->where(function (Builder $outer) use ($options) {
            foreach ($options as $option) {
                $outer->orWhere(fn (Builder $q) => self::conditions($q, $option));
            }
        });
    }

    /** Las condiciones de UNA opción, para poder combinarlas con OR. */
    private static function conditions(Builder $q, string $option): Builder
    {
        return match ($option) {
            'wa_blocked'    => $q->whereIn('status', self::WA_BLOCKED_STATUSES),
            'wa_snoozed'    => self::waAlive($q)->where('snoozed_until', '>', now()),
            'wa_hold'       => self::waAwake($q)->where('wa_marketing_hold_until', '>', now()),
            'wa_sent_today' => self::waOpen($q)->whereExists(self::sentToday('whatsapp')),
            'wa_cooldown'   => self::waOpen($q)
                ->whereNotExists(self::sentToday('whatsapp'))
                ->whereExists(self::sentWithinCooldown('whatsapp')),
            'wa_available'  => self::waOpen($q)->whereNotExists(self::sentWithinCooldown('whatsapp')),

            'sms_blocked'    => self::smsBlocked($q),
            'sms_sent_today' => self::smsOpen($q)->whereExists(self::sentToday('sms')),
            'sms_cooldown'   => self::smsOpen($q)
                ->whereNotExists(self::sentToday('sms'))
                ->whereExists(self::sentWithinCooldown('sms')),
            'sms_available'  => self::smsOpen($q)->whereNotExists(self::sentWithinCooldown('sms')),

            default => $q,
        };
    }

    // ── Eje WhatsApp: capas de precedencia ───────────────────────────────────
    /** Contacto que no está bloqueado por su estado (baja / inválido / inalcanzable). */
    private static function waAlive(Builder $query): Builder
    {
        return $query->whereNotIn('status', self::WA_BLOCKED_STATUSES);
    }

    /** Además, sin Pospuesto activo. */
    private static function waAwake(Builder $query): Builder
    {
        return self::waAlive($query)->where(fn ($q) => $q
            ->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
    }

    /** Además, sin el hold de 24h del error 131049. Es el "puede recibir hoy" de WhatsApp. */
    private static function waOpen(Builder $query): Builder
    {
        return self::waAwake($query)->where(fn ($q) => $q
            ->whereNull('wa_marketing_hold_until')->orWhere('wa_marketing_hold_until', '<=', now()));
    }

    // ── Eje SMS ──────────────────────────────────────────────────────────────
    /** La baja es cross-channel; lo demás son banderas propias de SMS. */
    private static function smsBlocked(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q
            ->where('status', 'opted_out')
            ->orWhere('sms_opt_out', true)
            ->orWhere('sms_blocked', true)
            ->orWhere('sms_invalid', true));
    }

    private static function smsOpen(Builder $query): Builder
    {
        return $query->where('status', '!=', 'opted_out')
            ->where('sms_opt_out', false)
            ->where('sms_blocked', false)
            ->where('sms_invalid', false);
    }

    // ── Subconsultas sobre message_log ───────────────────────────────────────
    /** EXISTS: ¿le entró algo por este canal hoy (día CST)? */
    private static function sentToday(string $channel): callable
    {
        $from = now('America/Mexico_City')->startOfDay()->utc();
        $to   = now('America/Mexico_City')->endOfDay()->utc();

        return fn (QueryBuilder $q) => self::logsFor($q, $channel)->whereBetween('sent_at', [$from, $to]);
    }

    /** EXISTS: ¿le entró algo por este canal dentro de la ventana de enfriamiento? */
    private static function sentWithinCooldown(string $channel): callable
    {
        $days = max(7, (int) Setting::get('cooldown_days', 30));
        $from = now()->subDays($days);

        return fn (QueryBuilder $q) => self::logsFor($q, $channel)->where('sent_at', '>', $from);
    }

    private static function logsFor(QueryBuilder $q, string $channel): QueryBuilder
    {
        return $q->from('message_log')
            ->whereColumn('message_log.to_number', 'contacts.phone')
            ->where('message_log.channel', $channel)
            ->whereIn('message_log.status', self::SENT_STATUSES)
            ->selectRaw('1');
    }
}
