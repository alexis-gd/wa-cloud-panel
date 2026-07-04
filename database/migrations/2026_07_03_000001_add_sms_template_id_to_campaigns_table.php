<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campañas SMS ahora exigen plantilla (no texto libre), igual que WhatsApp exige plantilla
 * aprobada. `sms_template_id` referencia la plantilla usada; `sms_body` guarda el snapshot
 * del cuerpo al crear (para que el envío no cambie si la plantilla se edita/borra después).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->foreignId('sms_template_id')
                ->nullable()
                ->constrained('sms_templates')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sms_template_id');
        });
    }
};
