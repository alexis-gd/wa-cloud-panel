<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'channel',
        'template_name',
        'language_code',
        'body_vars',
        'sms_body',
        'sms_template_id',
        'tag_id',
        'phone_number_id',
        'status',
        'total_contacts',
        'sent_count',
        'delivered_count',
        'failed_count',
        'scheduled_at',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'body_vars'    => 'array',
        'scheduled_at' => 'datetime',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function tag()
    {
        return $this->belongsTo(Tag::class);
    }

    public function phoneNumber()
    {
        return $this->belongsTo(PhoneNumber::class);
    }

    public function smsTemplate()
    {
        return $this->belongsTo(SmsTemplate::class);
    }

    public function getProgressPercentageAttribute(): int
    {
        if ($this->total_contacts === 0) {
            return 0;
        }
        return (int) round(($this->sent_count / $this->total_contacts) * 100);
    }
}
