<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SMS entrante registrado desde el webhook del gateway (evento sms:received).
 * Se muestra como lista plana en "Respuestas SMS" — no es una conversación.
 */
class SmsInboundMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'gateway_message_id',
        'contact_id',
        'from_number',
        'body',
        'action',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
