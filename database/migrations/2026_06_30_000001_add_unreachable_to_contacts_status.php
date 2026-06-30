<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL no permite modificar un enum con ->change() sin doctrine/dbal,
        // así que se altera la columna con SQL crudo.
        DB::statement(
            "ALTER TABLE contacts MODIFY COLUMN status "
            . "ENUM('active', 'opted_out', 'invalid', 'unreachable') "
            . "NOT NULL DEFAULT 'active'"
        );
    }

    public function down(): void
    {
        // Revertir contactos unreachable a active antes de quitar el valor del enum,
        // de lo contrario MySQL truncaría/fallaría al reducir el enum.
        DB::statement("UPDATE contacts SET status = 'active' WHERE status = 'unreachable'");

        DB::statement(
            "ALTER TABLE contacts MODIFY COLUMN status "
            . "ENUM('active', 'opted_out', 'invalid') "
            . "NOT NULL DEFAULT 'active'"
        );
    }
};
