<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PhoneNumber extends Model
{
    use HasFactory;

    protected $fillable = [
        'display_name',
        'phone_number_id',
        'waba_id',
        'token',
        'is_active',
        'daily_limit',
    ];

    protected $casts = [
        'token'     => 'encrypted', // AES-256 usando APP_KEY del .env
        'is_active' => 'boolean',
    ];
}
