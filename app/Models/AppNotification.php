<?php

namespace App\Models;

use App\Events\NotificationCreated;
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

    // Al crear cualquier notificacion, emitir el evento de tiempo real (campanita en vivo).
    // Se engancha aqui para cubrir todos los sitios que crean notificaciones sin repetir codigo.
    protected static function booted(): void
    {
        static::created(function (AppNotification $notification) {
            event(NotificationCreated::fromModel($notification));
        });
    }
}
