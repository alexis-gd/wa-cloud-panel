<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            // Canal del mensaje. Default whatsapp para no romper los registros existentes.
            $table->enum('channel', ['whatsapp', 'sms'])->default('whatsapp')->after('phone_number_id');

            // Texto plano del SMS enviado (WhatsApp usa template_name + body_vars).
            $table->text('sms_body')->nullable()->after('body_vars');

            // SMS no tiene número WA ni plantilla → estas columnas dejan de ser obligatorias.
            $table->unsignedBigInteger('phone_number_id')->nullable()->change();
            $table->string('template_name')->nullable()->change();
            $table->string('language_code', 10)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('message_log', function (Blueprint $table) {
            $table->dropColumn(['channel', 'sms_body']);
            $table->unsignedBigInteger('phone_number_id')->nullable(false)->change();
            $table->string('template_name')->nullable(false)->change();
            $table->string('language_code', 10)->nullable(false)->change();
        });
    }
};
