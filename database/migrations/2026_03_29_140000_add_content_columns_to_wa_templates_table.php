<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            $table->string('header_type')->nullable()->after('category');   // NONE, TEXT, IMAGE, VIDEO, DOCUMENT
            $table->string('header_text')->nullable()->after('header_type');
            $table->string('header_image_url')->nullable()->after('header_text');
            $table->text('body_text')->nullable()->after('header_image_url');
            $table->string('footer_text')->nullable()->after('body_text');
            $table->json('buttons')->nullable()->after('footer_text');       // [{type, text}]
            $table->string('quality_score')->nullable()->after('status');   // GREEN, YELLOW, RED
            $table->string('rejection_reason')->nullable()->after('quality_score');
        });
    }

    public function down(): void
    {
        Schema::table('wa_templates', function (Blueprint $table) {
            $table->dropColumn([
                'header_type', 'header_text', 'header_image_url',
                'body_text', 'footer_text', 'buttons',
                'quality_score', 'rejection_reason',
            ]);
        });
    }
};
