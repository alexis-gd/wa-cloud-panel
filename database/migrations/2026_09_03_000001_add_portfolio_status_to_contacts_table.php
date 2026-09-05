<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estado del contacto en la cartera del cliente (LIQUIDADO, BURO, BAJA...).
     *
     * Es una columna y no una etiqueta a proposito. Una etiqueta la pone y la quita el
     * operador, y es de muchos a muchos; esto es UN dato que le pertenece al sistema del
     * cliente y que cambia con el tiempo: quien hoy esta en BURO manana puede liquidar.
     * Como columna se puede refrescar cada noche y el filtro siempre dice la verdad de hoy.
     *
     * Ojo: este "BAJA" es el de SU cartera (dejo de ser su cliente), NO nuestro opt-out.
     * Nuestra baja vive en `contacts.status` y es irreversible por ley.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            // 60 caracteres: los valores vistos son de una palabra, con margen de sobra por
            // si el cliente agrega una clasificacion mas larga sin avisarnos.
            $table->string('portfolio_status', 60)->nullable()->after('source');

            // El filtro de Contactos consulta por este campo sobre 200k filas.
            $table->index('portfolio_status', 'idx_contacts_portfolio_status');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('idx_contacts_portfolio_status');
            $table->dropColumn('portfolio_status');
        });
    }
};
