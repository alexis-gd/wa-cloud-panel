<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    protected $fillable = ['name', 'slug'];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function (Tag $tag) {
            if (empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class);
    }

    /**
     * Campañas segmentadas con esta etiqueta. Es `nullOnDelete`, por eso borrar una etiqueta
     * necesita el guardia de TagDeletionGuard: una campaña sin `tag_id` deja de estar
     * segmentada y saldría a toda la base.
     */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }
}
