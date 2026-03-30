<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversation extends Model
{
    protected $fillable = [
        'contact_id',
        'direction',
        'message_type',
        'body',
        'wa_message_id',
        'status',
        'window_open',
    ];

    protected $casts = [
        'window_open' => 'boolean',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
