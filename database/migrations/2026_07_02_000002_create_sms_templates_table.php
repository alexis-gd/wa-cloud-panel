<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plantillas de SMS. A diferencia de las de WhatsApp (wa_templates, aprobadas por Meta),
 * estas son locales: texto reutilizable que el operador crea y usa de inmediato, sin
 * aprobacion externa. Se administran en la misma vista "Plantillas", pestana SMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};
