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
        Schema::table('phone_numbers', function (Blueprint $table) {
            // Circuit breaker: timestamp hasta cuando el número está pausado (null = activo)
            $table->timestamp('paused_until')->nullable()->after('daily_limit');
        });
    }

    public function down(): void
    {
        Schema::table('phone_numbers', function (Blueprint $table) {
            $table->dropColumn('paused_until');
        });
    }
};
