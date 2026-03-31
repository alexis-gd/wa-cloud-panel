<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            // Cubre: sent_today (phone_number_id + sent_at + status)
            // y cooldown check en el job (to_number + sent_at + status)
            $table->index(['phone_number_id', 'sent_at', 'status'], 'idx_logs_phone_sent_status');
            $table->index(['to_number', 'sent_at', 'status'],       'idx_logs_to_sent_status');
        });
    }

    public function down(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            $table->dropIndex('idx_logs_phone_sent_status');
            $table->dropIndex('idx_logs_to_sent_status');
        });
    }
};
