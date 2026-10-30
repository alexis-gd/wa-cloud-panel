<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de corridas del cron que da de alta contactos desde el API del cliente.
 *
 * Antes solo se guardaba la ultima (`Setting contact_sync_last_run`), que se sobreescribe. Si
 * el cron corre hoy y falla, el rastro de la corrida buena de ayer se pierde justo cuando hace
 * falta compararlas. Con una fila por corrida se puede ver "desde cuando viene fallando" y
 * "cuantos entraron cada dia", que son las dos preguntas reales.
 *
 * El Setting se mantiene por compatibilidad: lo lee `contactos:ultima-corrida`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->boolean('ok')->default(true);
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('refreshed')->default(0);
            $table->unsignedInteger('invalid')->default(0);
            $table->unsignedInteger('duplicates')->default(0);
            $table->string('error', 500)->nullable();
            // El resumen completo tal como lo imprime el comando, por si hace falta el detalle.
            $table->json('detail')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_sync_runs');
    }
};
