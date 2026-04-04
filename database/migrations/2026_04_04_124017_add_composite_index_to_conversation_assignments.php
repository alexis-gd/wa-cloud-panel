<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_assignments', function (Blueprint $table) {
            // Cubre: SELECT MAX(id) ... WHERE contact_id = ? (usado en ConversationController
            // para encontrar la asignación más reciente de cada contacto).
            // Sin este índice MySQL escanea todas las filas de contact_id; con él es O(log n).
            $table->index(['contact_id', 'id'], 'idx_assignments_contact_id');
        });
    }

    public function down(): void
    {
        Schema::table('conversation_assignments', function (Blueprint $table) {
            $table->dropIndex('idx_assignments_contact_id');
        });
    }
};
