<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->enum('channel', ['whatsapp', 'sms'])->default('whatsapp')->after('name');

            // Texto del SMS. WhatsApp usa template_name + body_vars; SMS usa sms_body.
            $table->text('sms_body')->nullable()->after('body_vars');

            // Una campaña SMS no tiene plantilla WA ni número WA asignado.
            $table->string('template_name', 100)->nullable()->change();
            $table->unsignedBigInteger('phone_number_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['channel', 'sms_body']);
            $table->string('template_name', 100)->nullable(false)->change();
            $table->unsignedBigInteger('phone_number_id')->nullable(false)->change();
        });
    }
};
