<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('message_type', ['text', 'button_reply', 'template'])->default('text');
            $table->text('body');
            $table->string('wa_message_id')->nullable()->index();
            $table->enum('status', ['received', 'sent', 'delivered', 'read', 'failed'])->default('received');
            $table->boolean('window_open')->default(false)->comment('Ventana 24h activa para respuesta libre');
            $table->timestamps();

            $table->index(['contact_id', 'created_at']);
        });

        Schema::create('quick_replies', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_replies');
        Schema::dropIfExists('conversations');
    }
};
