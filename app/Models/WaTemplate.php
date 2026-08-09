<?php

namespace App\Models;

use App\Services\WhatsApp\TemplateImage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WaTemplate extends Model
{
    use HasFactory;

    /**
     * La imagen de encabezado no vive en BD sino como archivo (`{plantilla}.jpg|png`), así que
     * el panel necesita saber si está y dónde. `needs_image` es la señal de que la plantilla
     * NO se puede usar todavía: sin archivo local, Meta no entrega el mensaje.
     */
    protected $appends = ['image_url', 'needs_image'];

    protected $fillable = [
        'name', 'language_code', 'category', 'status', 'description', 'is_active', 'is_hidden',
        'header_type', 'header_text', 'header_image_url',
        'body_text', 'footer_text', 'buttons',
        'quality_score', 'rejection_reason',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_hidden' => 'boolean',
        'buttons'   => 'array',
    ];

    /** Ruta para mostrar la imagen en el panel; null si aún no se ha subido. */
    public function getImageUrlAttribute(): ?string
    {
        return (new TemplateImage())->panelUrl($this->name);
    }

    /** True si la plantilla lleva imagen de encabezado pero falta el archivo local. */
    public function getNeedsImageAttribute(): bool
    {
        return $this->header_type === 'IMAGE' && ! (new TemplateImage())->exists($this->name);
    }
}
