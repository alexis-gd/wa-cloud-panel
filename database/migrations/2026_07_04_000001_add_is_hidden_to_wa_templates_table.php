<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visibilidad de plantillas: el superadmin puede ocultar plantillas (ej. hello_world de
 * prueba) para que no aparezcan al operador ni en el selector de campañas. NO las borra
 * (siguen en Meta y en la BD); solo las quita de la vista del dia a dia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });
    }
};
