<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $table = 'app_notifications';

    // Laravel pone created_at desde PHP (UTC) — evita depender del timezone de MySQL
    public $timestamps = true;
    const  UPDATED_AT  = null;

    protected $fillable = [
        'type',
        'title',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at'    => 'datetime',
        'created_at' => 'datetime',
    ];
}
