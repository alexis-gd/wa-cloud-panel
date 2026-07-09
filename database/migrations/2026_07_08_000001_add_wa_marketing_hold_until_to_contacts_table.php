<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hold de 24h por error 131049 (tope de marketing POR USUARIO de WhatsApp).
     * Cuando Meta capa a un contacto, este campo guarda hasta cuándo NO reintentarle
     * una plantilla de marketing por WhatsApp. Es cumplimiento Meta (24h exactas),
     * separado del enfriamiento anti-spam del negocio (cooldown_days) y del Pospuesto.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('wa_marketing_hold_until')->nullable()->after('snoozed_until');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('wa_marketing_hold_until');
        });
    }
};
