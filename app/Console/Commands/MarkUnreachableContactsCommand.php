<?php

namespace App\Console\Commands;

use App\Models\Contact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MarkUnreachableContactsCommand extends Command
{
    protected $signature   = 'wa:mark-unreachable';
    protected $description  = 'Marca inalcanzables: 3 no-entregados seguidos, o 2+ con 30+ días sin ninguna entrega';

    /**
     * Estados que cuentan como "no le llegó".
     *
     * `sent` es el silencio: salió y Meta nunca confirmó nada. `failed` es el fracaso explícito,
     * el que llega por webhook (131026: el número no tiene WhatsApp). Antes solo se contaba el
     * silencio, así que 820 números sin WhatsApp seguían entrando a cada campaña sin que nada los
     * sacara - y volver a escribirle a un número inexistente es de las causas directas de que Meta
     * le baje la calidad al número.
     */
    private const UNDELIVERED_STATUSES = ['sent', 'failed'];

    /**
     * Tope fijo de mensajes "enviados" seguidos sin entrega antes de sacar al contacto.
     * NO depende del enfriamiento (cooldown_days): así la exposición a un número que no
     * entrega queda acotada aunque el cliente baje el enfriamiento. Cualquier 'delivered'/'read'
     * reinicia la cuenta. Es una protección de la cuenta - por eso no es configurable.
     */
    private const CONSECUTIVE_UNDELIVERED_LIMIT = 3;

    /** Los estados de arriba, listos para meter en un IN (...) de SQL crudo. */
    private static function listaSql(): string
    {
        return "'" . implode("','", self::UNDELIVERED_STATUSES) . "'";
    }

    public function handle(): int
    {
        // WhatsApp expira mensajes no entregados a los 30 días. Este 30 es de WhatsApp,
        // NO el enfriamiento del cliente: por eso va fijo y no lee cooldown_days.
        $cutoff = now()->subDays(30)->utc();

        $marked = Contact::query()
            ->where('status', 'active')
            ->where(function ($outer) use ($cutoff) {
                // ── Regla A (respaldo lento): 2+ 'sent' cuyo más antiguo supera 30 días
                //    y que NUNCA tuvo una entrega en toda su historia. ──
                $outer->where(function ($a) use ($cutoff) {
                    $a->whereRaw(
                        '(SELECT COUNT(*) FROM message_log '
                        . 'WHERE message_log.to_number = contacts.phone '
                        . 'AND message_log.status IN (' . self::listaSql() . ')) >= 2'
                    )
                    ->whereRaw(
                        '(SELECT MIN(message_log.sent_at) FROM message_log '
                        . 'WHERE message_log.to_number = contacts.phone '
                        . 'AND message_log.status IN (' . self::listaSql() . ')) < ?',
                        [$cutoff]
                    )
                    ->whereNotExists(function ($q) {
                        $q->select('id')
                            ->from('message_log')
                            ->whereColumn('message_log.to_number', 'contacts.phone')
                            ->whereIn('message_log.status', ['delivered', 'read']);
                    });
                })
                // ── Regla B (tope fijo): N mensajes 'sent' seguidos SIN entrega desde la
                //    última entrega. Un 'delivered'/'read' reinicia la cuenta (solo cuentan
                //    los 'sent' posteriores a la última entrega). No espera 30 días. ──
                ->orWhereRaw(
                    '(SELECT COUNT(*) FROM message_log '
                    . 'WHERE message_log.to_number = contacts.phone '
                    . 'AND message_log.status IN (' . self::listaSql() . ') '
                    . 'AND message_log.sent_at > COALESCE('
                    . '(SELECT MAX(m2.sent_at) FROM message_log m2 '
                    . 'WHERE m2.to_number = contacts.phone '
                    . "AND m2.status IN ('delivered', 'read')), '1970-01-01 00:00:00')"
                    . ') >= ?',
                    [self::CONSECUTIVE_UNDELIVERED_LIMIT]
                );
            })
            ->update(['status' => 'unreachable']);

        $this->info("Contactos marcados como unreachable: {$marked}");
        Log::info('wa:mark-unreachable ejecutado', ['marked' => $marked]);

        return self::SUCCESS;
    }
}
