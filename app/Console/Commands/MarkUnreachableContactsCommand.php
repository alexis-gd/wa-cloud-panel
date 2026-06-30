<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\MessageLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MarkUnreachableContactsCommand extends Command
{
    protected $signature   = 'wa:mark-unreachable';
    protected $description = 'Marca como unreachable a los contactos con 2+ mensajes de 30+ días sin ninguna entrega';

    public function handle(): int
    {
        // WhatsApp expira mensajes no entregados a los 30 días. Un contacto con 2+
        // mensajes en 'sent' cuyo más antiguo supera ese umbral, y que NUNCA tuvo
        // un 'delivered'/'read' en su historia, está bloqueado o inalcanzable.
        // Se enlaza message_log con contacts por teléfono (to_number = phone),
        // la tabla message_log no tiene contact_id.
        $cutoff = now()->subDays(30)->utc();

        $marked = Contact::query()
            ->where('status', 'active')
            // 2+ mensajes 'sent' cuyo más antiguo es de 30+ días
            ->whereRaw(
                '(SELECT COUNT(*) FROM message_log '
                . 'WHERE message_log.to_number = contacts.phone '
                . "AND message_log.status = 'sent') >= 2"
            )
            ->whereRaw(
                '(SELECT MIN(message_log.sent_at) FROM message_log '
                . 'WHERE message_log.to_number = contacts.phone '
                . "AND message_log.status = 'sent') < ?",
                [$cutoff]
            )
            // Nunca tuvo una entrega exitosa en toda su historia
            ->whereNotExists(function ($q) {
                $q->select('id')
                    ->from('message_log')
                    ->whereColumn('message_log.to_number', 'contacts.phone')
                    ->whereIn('message_log.status', ['delivered', 'read']);
            })
            ->update(['status' => 'unreachable']);

        $this->info("Contactos marcados como unreachable: {$marked}");
        Log::info('wa:mark-unreachable ejecutado', ['marked' => $marked]);

        return self::SUCCESS;
    }
}
