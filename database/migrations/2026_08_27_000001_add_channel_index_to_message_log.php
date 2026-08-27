<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índice para el filtro de Entregabilidad de Contactos.
     *
     * El filtro pregunta por contacto "¿le entró algo por ESTE canal hoy / dentro del
     * enfriamiento?" con un EXISTS correlacionado. Los índices que ya existían
     * (idx_message_log_dedup, idx_logs_to_sent_status) no llevan `channel`, así que MySQL
     * tenía que leer todas las filas del número y descartar por canal. Con 200k contactos
     * y un pegado de 500 números eso se nota.
     */
    public function up(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            $table->index(['to_number', 'channel', 'status', 'sent_at'], 'idx_logs_to_channel_status_sent');
        });
    }

    public function down(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            $table->dropIndex('idx_logs_to_channel_status_sent');
        });
    }
};
