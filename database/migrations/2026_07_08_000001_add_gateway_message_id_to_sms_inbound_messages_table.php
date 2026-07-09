<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Id del mensaje entrante en el gateway (capcom6). Sirve de llave de deduplicación:
 * el reconcile de entrantes (sms:reconcile-received) le pide al teléfono re-exportar
 * los sms:received de una ventana, y esos llegan por el MISMO webhook. Sin esta llave
 * se duplicarían filas y se re-dispararían opt-outs. Nullable: entrantes viejos o sin
 * id no dedupean (comportamiento actual). Unique permite múltiples NULL en MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_inbound_messages', function (Blueprint $table) {
            $table->string('gateway_message_id')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('sms_inbound_messages', function (Blueprint $table) {
            $table->dropUnique(['gateway_message_id']);
            $table->dropColumn('gateway_message_id');
        });
    }
};
