<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('display_name');           // Nombre amigable, ej. "Número principal"
            $table->string('phone_number_id')->unique(); // ID de Meta (WA_PHONE_ID)
            $table->string('waba_id');                // ID de la cuenta WABA
            $table->text('token');                    // Token Bearer (encriptado por Eloquent)
            $table->boolean('is_active')->default(true);
            $table->integer('daily_limit')->default(250); // Límite diario actual (warm-up)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_numbers');
    }
};
