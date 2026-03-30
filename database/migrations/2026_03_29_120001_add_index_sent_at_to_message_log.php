<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            // Índice compuesto para las queries de dedup y cooldown
            $table->index(['to_number', 'status', 'sent_at'], 'idx_message_log_dedup');
        });
    }

    public function down(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            $table->dropIndex('idx_message_log_dedup');
        });
    }
};
