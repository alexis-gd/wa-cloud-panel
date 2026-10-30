<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quien invento el codigo: nosotros o la app del cliente.
 *
 * Importa para soporte. Cuando alguien reporte "el codigo no me coincide", la primera
 * pregunta es de donde salio: si lo genero la app, el problema puede estar de su lado (dos
 * peticiones seguidas, su propio almacenamiento), y si lo generamos nosotros, del nuestro.
 * Sin esta columna las dos situaciones se ven identicas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            // 'propio' = lo generamos nosotros · 'cliente' = lo mando la app en la peticion.
            $table->string('code_source', 10)->default('propio')->after('code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropColumn('code_source');
        });
    }
};
