<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una corrida del cron que da de alta contactos desde el API del cliente.
 *
 * Antes solo se guardaba la ultima, en un Setting que se sobreescribe. Si el cron falla hoy, el
 * rastro de la corrida buena de ayer desaparece justo cuando hace falta compararlas - y este
 * cron ya estuvo tres dias muerto sin que nadie se enterara.
 */
class ContactSyncRun extends Model
{
    protected $fillable = [
        'ok', 'received', 'inserted', 'refreshed', 'invalid', 'duplicates', 'error', 'detail',
    ];

    protected $casts = [
        'ok'     => 'boolean',
        'detail' => 'array',
    ];

    /**
     * Las corridas viejas no se borran solas.
     *
     * Una fila por dia son 365 al ano: no hay nada que limpiar. Si algun dia el cron corriera
     * cada hora, esto se revisa - pero hoy borrarlas perderia justo lo que se quiso guardar.
     */
    public function scopeRecientes($query, int $cuantas = 30)
    {
        return $query->latest('id')->limit($cuantas);
    }
}
