<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            // Cubre las queries de dashboard: WHERE created_at BETWEEN ? AND ? AND status IN (...)
            // Usado por: stats() monthly count, dailyStats() GROUP BY, monthlyHistory() GROUP BY
            $table->index(['created_at', 'status'], 'idx_logs_created_status');
        });
    }

    public function down(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            $table->dropIndex('idx_logs_created_status');
        });
    }
};
