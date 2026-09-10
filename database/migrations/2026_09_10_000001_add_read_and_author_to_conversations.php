<?php

use App\Models\Contact;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dos datos que le faltaban a la bandeja de Conversaciones.
 *
 * 1. `contacts.conversation_read_at` - hasta dónde se leyó esa conversación. Es COMPARTIDO por
 *    todo el equipo (decisión del cliente): si alguien la abre, queda leída para todos. Por eso
 *    vive en el contacto y no en una tabla por usuario.
 *
 * 2. `conversations.user_id` - quién mandó el mensaje saliente. Sin esto la lista no podía
 *    decir si el último mensaje fue del cliente o de un compañero, que es justo lo que el
 *    cliente pidió ver. Nulo en los entrantes (los manda el contacto, no un usuario).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('conversation_read_at')->nullable()->after('snoozed_until');
        });

        Schema::table('conversations', function (Blueprint $table) {
            // nullOnDelete y no cascade: si se borra un usuario, el mensaje se queda en el
            // historial. Perder la conversación por dar de baja a un empleado sería absurdo.
            $table->foreignId('user_id')->nullable()->after('contact_id')
                ->constrained()->nullOnDelete();
        });

        // Las conversaciones que ya existen arrancan LEÍDAS. Si no, el primer día que alguien
        // abra el panel se encuentra 900 globos rojos de mensajes que ya atendió hace semanas,
        // y el aviso deja de significar algo antes de estrenarse.
        DB::table('contacts')
            ->whereIn('id', DB::table('conversations')->distinct()->pluck('contact_id'))
            ->update(['conversation_read_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('conversation_read_at');
        });
    }
};
