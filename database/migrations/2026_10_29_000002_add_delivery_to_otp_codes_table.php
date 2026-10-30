<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmacion de entrega de los codigos (partida O1.5 de la cotizacion).
 *
 * Se guarda **aparte de `status`** a proposito: son dos preguntas distintas y mezclarlas
 * perderia una. `status` cuenta la vida del codigo (se mando, se uso, caduco); esto cuenta la
 * del mensaje (llego al telefono, lo leyo, rebotó). Un codigo entregado y nunca usado es un
 * caso normal - y es justo el que hay que poder distinguir de uno que nunca llego.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            // null mientras Meta no diga nada. Luego: delivered | read | failed.
            $table->string('delivery_status', 20)->nullable()->after('status');
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropColumn(['delivery_status', 'delivered_at']);
        });
    }
};
