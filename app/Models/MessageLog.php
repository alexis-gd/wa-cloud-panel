<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MessageLog extends Model
{
    use HasFactory;
    protected $table = 'message_log';

    protected $fillable = [
        'phone_number_id',
        'campaign_id',
        'channel',
        'to_number',
        'template_name',
        'language_code',
        'body_vars',
        'sms_body',
        'wa_message_id',
        'status',
        'error_message',
        'delivery_error_code',
        'delivery_error_title',
        'discard_reason',
        'sent_at',
    ];

    protected $casts = [
        'body_vars' => 'array',
        'sent_at'   => 'datetime',
    ];

    public function phoneNumber()
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    // Crea el registro ANTES de llamar a la API (per CLAUDE.md regla #2)
    public static function logSend(int $phoneNumberId, string $to, string $template, string $lang, array $vars = [], ?int $campaignId = null): self
    {
        return self::create([
            'phone_number_id' => $phoneNumberId,
            'campaign_id'     => $campaignId,
            'to_number'       => $to,
            'template_name'   => $template,
            'language_code'   => $lang,
            'body_vars'       => $vars,
            'status'          => 'pending',
        ]);
    }

    // Crea el registro de un SMS ANTES de llamar al gateway (misma regla que logSend WA).
    // SMS no tiene phone_number_id (número WA) ni plantilla: guarda el texto en sms_body.
    public static function logSmsSend(string $to, string $body, ?int $campaignId = null): self
    {
        return self::create([
            'campaign_id' => $campaignId,
            'channel'     => 'sms',
            'to_number'   => $to,
            'sms_body'    => $body,
            'status'      => 'pending',
        ]);
    }

    // Registra un SMS descartado (opt-out / cooldown / dedup / etc.). Espejo de logDiscard WA.
    public static function logSmsDiscard(?int $campaignId, string $to, string $body, string $reason): self
    {
        return self::create([
            'campaign_id'    => $campaignId,
            'channel'        => 'sms',
            'to_number'      => $to,
            'sms_body'       => $body,
            'status'         => 'discarded',
            'discard_reason' => $reason,
            'sent_at'        => now(),
        ]);
    }

    // Actualiza con la respuesta del gateway SMS. El MessageSid del proveedor se guarda
    // en wa_message_id (columna genérica de id del proveedor, reutilizada para SMS).
    public function updateFromSmsResponse(array $response): void
    {
        if ($response['ok'] && ! empty($response['message_id'])) {
            $this->update([
                'wa_message_id' => $response['message_id'],
                'status'        => 'sent',
                'sent_at'       => now(),
            ]);
        } else {
            $this->update([
                'status'        => 'failed',
                'error_message' => json_encode($response['error'] ?? $response),
            ]);
        }
    }

    // Registra un contacto descartado (cooldown / snooze / opted_out / dedup_today)
    public static function logDiscard(int $phoneNumberId, ?int $campaignId, string $to, string $template, string $lang, string $reason): self
    {
        return self::create([
            'phone_number_id' => $phoneNumberId,
            'campaign_id'     => $campaignId,
            'to_number'       => $to,
            'template_name'   => $template,
            'language_code'   => $lang,
            'body_vars'       => [],
            'status'          => 'discarded',
            'discard_reason'  => $reason,
            'sent_at'         => now(),
        ]);
    }

    // Actualiza con la respuesta de Meta
    public function updateFromResponse(array $response): void
    {
        if ($response['ok'] && isset($response['body']['messages'][0]['id'])) {
            $this->update([
                'wa_message_id' => $response['body']['messages'][0]['id'],
                'status'        => 'sent',
                'sent_at'       => now(),
            ]);
        } else {
            $this->update([
                'status'        => 'failed',
                'error_message' => json_encode($response['body']['error'] ?? $response['body']),
            ]);
        }
    }

    // Actualiza el status desde eventos del webhook (delivered, read, failed)
    public function updateStatus(string $status, ?int $errorCode = null, ?string $errorTitle = null): void
    {
        $data = ['status' => $status];

        if ($errorCode !== null) {
            $data['delivery_error_code']  = $errorCode;
            $data['delivery_error_title'] = $errorTitle;
        }

        $this->update($data);
    }
}
