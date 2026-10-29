<?php

namespace App\Services\WhatsApp;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\MessageLog;
use App\Models\PhoneNumber;

/**
 * Todo lo que tiene que cumplirse antes de mandarle algo a un contacto desde Conversaciones.
 *
 * Vive aparte del controlador porque la misma lista la necesitan el envio de texto y el de
 * archivos, y porque son las reglas que protegen la cuenta de Meta: si una se olvidara en uno
 * de los dos caminos, el agujero no se veria hasta que Meta reaccionara. Mandarle una foto a
 * alguien que se dio de baja es exactamente igual de grave que mandarle un texto.
 *
 * El orden importa y va de lo mas grave a lo mas circunstancial: primero la baja (es legal),
 * luego el bloqueo por fallo de entrega, luego la ventana de 24h, y al final el estado del
 * numero que enviaria.
 */
class OutboundGuard
{
    /**
     * Mensajes para el operador segun el codigo con el que Meta rechazo el ultimo envio.
     *
     * Estan en lenguaje de operador a proposito: el codigo de Meta no le dice nada a quien
     * esta atendiendo un chat.
     */
    private const MOTIVOS = [
        131049 => 'Meta bloqueó temporalmente las entregas a este número por calidad. Intenta más tarde.',
        131048 => 'Meta detectó actividad inusual. Los mensajes a este número están pausados.',
        131026 => 'Este número no tiene WhatsApp activo.',
        131050 => 'Este contacto se dio de baja de los mensajes de marketing desde WhatsApp.',
        368    => 'La cuenta de WhatsApp fue bloqueada temporalmente por Meta.',
        132001 => 'La plantilla usada no fue aprobada por Meta.',
    ];

    /**
     * @return array{ok: bool, phone: ?PhoneNumber, code: ?string, message: ?string, status: int}
     */
    public function revisar(Contact $contact): array
    {
        if ($contact->status === 'opted_out') {
            return $this->no('OPTED_OUT', 'El contacto tiene opt-out activo.');
        }

        $bloqueo = $this->bloqueoPorFalloDeEntrega($contact);

        if ($bloqueo !== null) {
            return $this->no('DELIVERY_BLOCKED', "No se puede enviar: {$bloqueo}");
        }

        if (! $this->ventanaAbierta($contact)) {
            return $this->no(
                'WINDOW_CLOSED',
                'La ventana de 24h está cerrada. Usa una plantilla para reabrir la conversación.',
            );
        }

        $phone = PhoneNumber::where('is_active', true)->first();

        if (! $phone) {
            return $this->no('NO_PHONE_NUMBER', 'No hay número de WhatsApp activo configurado.', 500);
        }

        if ($phone->isPaused()) {
            $hora = $phone->paused_until->setTimezone('America/Mexico_City')->format('g:i A');

            return $this->no(
                'PHONE_PAUSED',
                "Este número no puede recibir mensajes ahora. Meta bloqueó temporalmente las entregas. Vuelve a intentarlo después de las {$hora}.",
            );
        }

        return ['ok' => true, 'phone' => $phone, 'code' => null, 'message' => null, 'status' => 200];
    }

    /**
     * La ventana de servicio de WhatsApp: fuera de 24h desde el ultimo mensaje del contacto,
     * Meta solo deja mandar plantillas.
     */
    public function ventanaAbierta(Contact $contact): bool
    {
        return Conversation::where('contact_id', $contact->id)
            ->where('direction', 'inbound')
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();
    }

    /**
     * Si el ultimo envio a este numero fallo con un motivo conocido, no se insiste.
     *
     * Con una excepcion: si el contacto escribio DESPUES del fallo, el fallo quedo superado
     * (el numero si funciona) y el bloqueo no aplica.
     */
    private function bloqueoPorFalloDeEntrega(Contact $contact): ?string
    {
        $ultimo = MessageLog::where('to_number', $contact->phone)
            ->whereIn('status', ['failed', 'sent', 'delivered', 'read'])
            ->latest('updated_at')
            ->first();

        if (! $ultimo || $ultimo->status !== 'failed' || ! $ultimo->delivery_error_code) {
            return null;
        }

        $respondioDespues = Conversation::where('contact_id', $contact->id)
            ->where('direction', 'inbound')
            ->where('created_at', '>', $ultimo->updated_at)
            ->exists();

        if ($respondioDespues) {
            return null;
        }

        return self::MOTIVOS[$ultimo->delivery_error_code]
            ?? 'El último mensaje a este contacto no fue entregado por Meta.';
    }

    private function no(string $code, string $message, int $status = 422): array
    {
        return ['ok' => false, 'phone' => null, 'code' => $code, 'message' => $message, 'status' => $status];
    }
}
