<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bandeja plana de SMS entrantes (evento sms:received del gateway). NO es un chat:
 * es un inbox de solo lectura (fecha · número · mensaje · acción). Separada de
 * `conversations` (que es WhatsApp-céntrica: window_open, wa_message_id, plantillas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_inbound_messages', function (Blueprint $table) {
            $table->id();
            // Nullable: un entrante puede venir de un número que no está en `contacts`.
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_number')->index();
            $table->text('body');
            // Acción automática que disparó el mensaje (ej. 'opt_out' por STOP). Null = ninguna.
            $table->string('action')->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_inbound_messages');
    }
};
