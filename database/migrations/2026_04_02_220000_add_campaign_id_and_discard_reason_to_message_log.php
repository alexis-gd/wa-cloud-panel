<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            $table->unsignedBigInteger('campaign_id')->nullable()->after('phone_number_id');
            $table->string('discard_reason', 50)->nullable()->after('error_message');

            $table->foreign('campaign_id')->references('id')->on('campaigns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            $table->dropForeign(['campaign_id']);
            $table->dropColumn(['campaign_id', 'discard_reason']);
        });
    }
};
