<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            // Las URLs CDN de Meta pueden superar 255 chars — cambiamos a TEXT
            $table->text('header_image_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            $table->string('header_image_url', 255)->nullable()->change();
        });
    }
};
