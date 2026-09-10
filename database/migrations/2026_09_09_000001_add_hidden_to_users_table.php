<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usuarios que no salen en la pantalla de Usuarios.
 *
 * Existe por una necesidad de auditoría: Alexis y Joseph compartían la misma cuenta superadmin,
 * así que era imposible saber quién hizo qué. Con una cuenta propia por persona el rastro sirve,
 * y ocultarla evita que el cliente vea usuarios técnicos que no le corresponde administrar.
 *
 * Ocultar NO es un permiso: el usuario oculto entra al panel igual que cualquier otro. Lo único
 * que cambia es que no se lista ni se puede administrar desde la UI (ver UserController).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('hidden')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('hidden');
        });
    }
};
