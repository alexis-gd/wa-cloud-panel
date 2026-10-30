<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quien dio de alta y quien dio de baja cada contacto.
 *
 * Hasta ahora `contacts` solo decia DE DONDE salio (`source`), nunca QUIEN. Con varios
 * operadores subiendo Excel y borrando filas, una pregunta tan simple como "quien borro a este
 * cliente" no tenia respuesta - y los contactos son el activo del negocio.
 *
 * `nullOnDelete` y no cascade: si alguien deja la empresa y se borra su usuario, el contacto se
 * queda; lo que se pierde es el nombre de quien lo subio, no el contacto.
 *
 * Nulo tambien significa algo: lo puso el sistema (el cron del API del cliente), no una persona.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('source')
                  ->constrained('users')->nullOnDelete();
            $table->foreignId('deleted_by')->nullable()->after('created_by')
                  ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('deleted_by');
        });
    }
};
