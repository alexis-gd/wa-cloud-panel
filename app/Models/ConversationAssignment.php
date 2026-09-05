<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationAssignment extends Model
{
    public $timestamps = false;

    protected $fillable = ['contact_id', 'user_id', 'assigned_by_id', 'assigned_at', 'action'];

    /** Movimientos posibles. La tabla es un libro que solo crece: nada se borra ni se edita. */
    public const ACTION_AUTO     = 'auto';      // el sistema repartió al recibir el primer mensaje
    public const ACTION_MANUAL   = 'manual';    // alguien la asignó estando libre
    public const ACTION_CLAIM    = 'claim';     // el agente se la tomó
    public const ACTION_REASSIGN = 'reassign';  // alguien se la pasó de un agente a otro
    public const ACTION_RELEASE  = 'release';   // quedó sin asignar (baja del contacto o suelta)

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Quién hizo el movimiento. NULL = lo hizo el sistema. */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_id');
    }
}
