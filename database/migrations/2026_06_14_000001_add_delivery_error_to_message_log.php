<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            $table->integer('delivery_error_code')->nullable()->after('error_message');
            $table->string('delivery_error_title')->nullable()->after('delivery_error_code');
        });
    }

    public function down(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            $table->dropColumn(['delivery_error_code', 'delivery_error_title']);
        });
    }
};
