<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageLog extends Model
{
    protected $table = 'message_log';

    protected $fillable = [
        'phone_number_id',
        'to_number',
        'template_name',
        'language_code',
        'body_vars',
        'wa_message_id',
        'status',
        'error_message',
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

    // Crea el registro ANTES de llamar a la API (per CLAUDE.md regla #2)
    public static function logSend(int $phoneNumberId, string $to, string $template, string $lang, array $vars = []): self
    {
        return self::create([
            'phone_number_id' => $phoneNumberId,
            'to_number'       => $to,
            'template_name'   => $template,
            'language_code'   => $lang,
            'body_vars'       => $vars,
            'status'          => 'pending',
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
    public function updateStatus(string $status): void
    {
        $this->update(['status' => $status]);
    }
}
