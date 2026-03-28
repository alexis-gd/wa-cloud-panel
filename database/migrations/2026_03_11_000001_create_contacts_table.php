<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->unique();   // normalizado: 52XXXXXXXXXX
            $table->string('name', 100)->nullable();
            $table->enum('status', ['active', 'opted_out', 'invalid'])->default('active');
            $table->string('source', 50)->default('excel'); // excel | manual | api
            $table->text('notes')->nullable();
            $table->timestamp('opted_out_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
