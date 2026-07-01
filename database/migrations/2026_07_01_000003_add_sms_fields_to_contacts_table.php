<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // Estado SMS independiente del canal WhatsApp (status). Un contacto puede estar
            // active para WA pero con sms_opt_out=true si pidió baja solo por SMS, o al revés.
            $table->boolean('sms_opt_out')->default(false)->after('opted_out_source');
            $table->boolean('sms_blocked')->default(false)->after('sms_opt_out');
            $table->boolean('sms_invalid')->default(false)->after('sms_blocked');

            // Contador de rebotes SMS consecutivos → auto-blacklist a los 3 (regla contexto-twilio-sms).
            $table->unsignedSmallInteger('sms_bounce_count')->default(0)->after('sms_invalid');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn(['sms_opt_out', 'sms_blocked', 'sms_invalid', 'sms_bounce_count']);
        });
    }
};
