<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'description'];

    /** Obtiene un valor de configuración. Devuelve $default si la clave no existe. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        return $setting?->value ?? $default;
    }

    /** Actualiza o crea un valor de configuración. */
    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => (string) $value],
        );
    }
}
