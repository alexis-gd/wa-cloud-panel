<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');                   // Nombre de la plantilla en Meta, ej. "hello_world"
            $table->string('language_code', 10);      // Código de idioma, ej. "es_MX", "en_US"
            $table->string('category', 50);           // MARKETING, UTILITY, AUTHENTICATION
            $table->text('description')->nullable();  // Descripción interna para el equipo
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_templates');
    }
};
