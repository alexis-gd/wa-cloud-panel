<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Autoría e historial de las asignaciones de conversación.
     *
     * La tabla guardaba solo "el contacto X quedó con el agente Y a tal hora": no se sabía
     * QUIÉN hizo el movimiento ni si fue automático, y soltar una conversación BORRABA los
     * registros, con lo que el historial desaparecía justo cuando más importa (cambios de
     * turno). Con esto la tabla pasa a ser un libro de movimientos que solo crece.
     */
    public function up(): void
    {
        Schema::table('conversation_assignments', function (Blueprint $table) {
            // Quién hizo el movimiento. NULL = lo hizo el sistema (reparto automático).
            $table->foreignId('assigned_by_id')->nullable()->after('user_id')
                  ->constrained('users')->nullOnDelete();

            // Qué movimiento fue, para poder contarlos y explicarlos en el modal.
            $table->enum('action', ['auto', 'manual', 'claim', 'reassign', 'release'])
                  ->default('manual')->after('assigned_by_id');

            $table->index(['user_id', 'assigned_at'], 'idx_assign_user_date');
        });

        // `user_id` pasa a admitir NULL para poder registrar la liberación como una fila más
        // ("quedó sin asignar") en vez de borrar el historial. Va fuera del Blueprint porque
        // cambiar una columna con llave foránea necesita soltarla y volverla a poner.
        Schema::table('conversation_assignments', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        DB::statement('ALTER TABLE conversation_assignments MODIFY user_id BIGINT UNSIGNED NULL');

        Schema::table('conversation_assignments', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Las filas que ya existían son del reparto automático o de una asignación manual
        // vieja; no hay forma de saber cuál, así que se marcan como 'auto' (el caso común)
        // y sin autor, que es la verdad: no se guardó quién fue.
        DB::table('conversation_assignments')->update(['action' => 'auto']);
    }

    public function down(): void
    {
        Schema::table('conversation_assignments', function (Blueprint $table) {
            $table->dropForeign(['assigned_by_id']);
            $table->dropIndex('idx_assign_user_date');
            $table->dropColumn(['assigned_by_id', 'action']);
        });
    }
};
