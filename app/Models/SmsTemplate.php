<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla de SMS local (no pasa por Meta). Texto reutilizable para campanas y pruebas.
 */
class SmsTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'body',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
