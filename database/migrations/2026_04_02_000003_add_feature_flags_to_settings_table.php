<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La tabla settings ya existe (key/value). Insertamos los feature flags por defecto.
        $flags = [
            'feature_daily_chart'   => '1', // gráfica 14 días en dashboard
            'feature_conversations' => '1', // módulo conversaciones
            'feature_export'        => '1', // exportar contactos/mensajes
            'feature_tags'          => '1', // tags y segmentación
            'feature_multi_agent'   => '1', // asignación de conversaciones
        ];

        foreach ($flags as $key => $value) {
            DB::table('settings')->insertOrIgnore(['key' => $key, 'value' => $value]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', [
            'feature_daily_chart',
            'feature_conversations',
            'feature_export',
            'feature_tags',
            'feature_multi_agent',
        ])->delete();
    }
};
