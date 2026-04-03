<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            // Cubre: WHERE campaign_id = ? ORDER BY sent_at DESC LIMIT 50
            // Sin este índice MySQL ordena hasta 200k filas antes de devolver los primeros 50.
            $table->index(['campaign_id', 'sent_at'], 'idx_logs_campaign_sent');
        });
    }

    public function down(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            $table->dropIndex('idx_logs_campaign_sent');
        });
    }
};
