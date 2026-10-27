<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversation extends Model
{
    protected $fillable = [
        'contact_id',
        'user_id',
        'direction',
        'message_type',
        'body',
        'wa_message_id',
        'status',
        'window_open',
        'media_id',
        'media_path',
        'media_mime',
        'media_size',
        'media_filename',
        'media_status',
    ];

    protected $casts = [
        'window_open' => 'boolean',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Quien mando el mensaje. Null en los entrantes: esos los manda el contacto, no un usuario.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Tipos de mensaje que llevan un archivo adjunto. */
    public const TIPOS_CON_ARCHIVO = ['image', 'document', 'audio', 'video'];

    /** ¿Este mensaje lleva archivo? Lo usan la vista, la ruta de descarga y la retencion. */
    public function tieneArchivo(): bool
    {
        return in_array($this->message_type, self::TIPOS_CON_ARCHIVO, true)
            && $this->media_id !== null;
    }

    /** El archivo ya esta en disco y se puede servir. */
    public function archivoListo(): bool
    {
        return $this->media_status === 'stored' && $this->media_path !== null;
    }
}
