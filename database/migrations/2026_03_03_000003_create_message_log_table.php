<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('phone_number_id')->constrained('phone_numbers');
            $table->string('to_number', 20);          // Número destino con código de país, ej. "+521234567890"
            $table->string('template_name');
            $table->string('language_code', 10);
            $table->json('body_vars')->nullable();     // Variables del cuerpo de la plantilla
            $table->string('wa_message_id')->nullable(); // ID que devuelve Meta al enviar
            $table->string('status', 30)->default('pending'); // pending, sent, delivered, read, failed
            $table->text('error_message')->nullable(); // Detalle del error si falló
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('to_number');
            $table->index('status');
            $table->index('wa_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_log');
    }
};
