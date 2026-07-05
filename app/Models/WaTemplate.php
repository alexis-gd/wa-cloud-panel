<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WaTemplate extends Model
{
    use HasFactory;

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
}
