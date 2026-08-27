<?php

namespace App\Services\Contacts;

use App\Models\MessageLog;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pinta el estado de entregabilidad de los contactos de UNA página: sent_today,
 * cooldown, snooze y el hold de Meta, por canal.
 *
 * Corre después de paginar (2 queries agregadas por página, no una por fila), así que
 * sirve para MOSTRAR. Para FILTRAR por esos mismos estados está DeliverabilityFilter,
 * que los traduce a SQL. Los dos deben cambiar juntos: si aquí se mueve la precedencia
 * de una etiqueta y allá no, el operador filtra una cosa y ve otra.
 */
class DeliverabilityBadges
{
    /**
     * Anexa a cada contacto de la página su estado de entregabilidad:
     * sent_today, cooldown_active, cooldown_until, deliverable.
     * Hace 2 queries agregadas sobre message_log para toda la página (sin N+1).
     */
    public static function attach($contacts): void
    {
        if ($contacts->isEmpty()) {
            return;
        }

        $phones = $contacts->pluck('phone')->all();

        $startOfDay = now('America/Mexico_City')->startOfDay()->utc();
        $endOfDay   = now('America/Mexico_City')->endOfDay()->utc();

        // Dedup y cooldown son POR CANAL (igual que los jobs SendWhatsAppMessage/SendSmsMessage):
        // un SMS enviado hoy no pone a WhatsApp "en cooldown" y viceversa. Por eso calculamos
        // los sets de "enviado hoy" y "ultimo envio" filtrando por canal.
        $sentTodaySetFor = function (string $channel) use ($phones, $startOfDay, $endOfDay): array {
            return array_flip(
                MessageLog::whereIn('to_number', $phones)
                    ->where('channel', $channel)
                    ->whereBetween('sent_at', [$startOfDay, $endOfDay])
                    ->whereIn('status', ['sent', 'delivered', 'read'])
                    ->distinct()
                    ->pluck('to_number')
                    ->all()
            );
        };

        $lastSentMapFor = function (string $channel) use ($phones): array {
            return MessageLog::whereIn('to_number', $phones)
                ->where('channel', $channel)
                ->whereIn('status', ['sent', 'delivered', 'read'])
                ->groupBy('to_number')
                ->select('to_number', DB::raw('MAX(sent_at) as last_sent'))
                ->pluck('last_sent', 'to_number')
                ->all();
        };

        $waSentTodaySet  = $sentTodaySetFor('whatsapp');
        $waLastSentMap   = $lastSentMapFor('whatsapp');
        $smsSentTodaySet = $sentTodaySetFor('sms');
        $smsLastSentMap  = $lastSentMapFor('sms');

        $cooldownDays = max(7, (int) Setting::get('cooldown_days', 30));

        // Devuelve [activo(bool), hasta(string|null)] segun el ultimo envio del canal.
        $cooldownState = function (?string $lastSent) use ($cooldownDays): array {
            if ($lastSent && now()->diffInDays($lastSent) < $cooldownDays) {
                return [true, Carbon::parse($lastSent)
                    ->addDays($cooldownDays)
                    ->setTimezone('America/Mexico_City')
                    ->format('Y-m-d')];
            }
            return [false, null];
        };

        foreach ($contacts as $contact) {
            // Eje WhatsApp: la identidad del contacto (opted_out/invalid/unreachable) bloquea WA.
            $waBlocked = in_array($contact->status, ['opted_out', 'invalid', 'unreachable'], true);
            // Eje SMS: opt-out es cross-channel (una baja bloquea ambos), mas las banderas propias
            // de SMS. Un "invalid/unreachable" de WhatsApp NO implica que el SMS falle.
            $smsBlocked = $contact->status === 'opted_out'
                || $contact->sms_opt_out || $contact->sms_blocked || $contact->sms_invalid;

            $snoozeActive = $contact->isSnoozeActive();
            $snoozeUntil  = $snoozeActive
                ? $contact->snoozed_until->setTimezone('America/Mexico_City')->format('Y-m-d')
                : null;

            $waSentToday = isset($waSentTodaySet[$contact->phone]);
            [$waCooldownActive, $waCooldownUntil] = $cooldownState($waLastSentMap[$contact->phone] ?? null);

            $smsSentToday = isset($smsSentTodaySet[$contact->phone]);
            [$smsCooldownActive, $smsCooldownUntil] = $cooldownState($smsLastSentMap[$contact->phone] ?? null);

            $contact->setAttribute('snooze_active', $snoozeActive);
            $contact->setAttribute('snooze_until', $snoozeUntil);

            // Genericos = eje WhatsApp (retrocompatibles: el front viejo y /contacts/check los usaban).
            $contact->setAttribute('sent_today', $waSentToday);
            $contact->setAttribute('cooldown_active', $waCooldownActive);
            $contact->setAttribute('cooldown_until', $waCooldownUntil);
            // Hold de 24h del error 131049 (tope de marketing POR USUARIO). El job ya lo
            // respeta y descarta; sin esto la lista decía "Disponible" y el operador no
            // entendía por qué su campaña descartaba a ese contacto.
            $marketingHold      = $contact->isWaMarketingHoldActive();
            $marketingHoldUntil = $marketingHold
                ? $contact->wa_marketing_hold_until->setTimezone('America/Mexico_City')->format('Y-m-d H:i')
                : null;

            $contact->setAttribute('wa_marketing_hold', $marketingHold);
            $contact->setAttribute('wa_marketing_hold_until_label', $marketingHoldUntil);
            $contact->setAttribute('deliverable', ! $waBlocked && ! $snoozeActive && ! $marketingHold && ! $waSentToday && ! $waCooldownActive);

            // Eje SMS (nuevos, para el segundo tag de Entregabilidad).
            // El snooze NO entra aquí: es por canal (solo WhatsApp). Ver contexto-sms.
            $contact->setAttribute('sms_sent_today', $smsSentToday);
            $contact->setAttribute('sms_cooldown_active', $smsCooldownActive);
            $contact->setAttribute('sms_cooldown_until', $smsCooldownUntil);
            $contact->setAttribute('sms_deliverable', ! $smsBlocked && ! $smsSentToday && ! $smsCooldownActive);
        }
    }
}
