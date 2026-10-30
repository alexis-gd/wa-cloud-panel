<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Codigos de verificacion (OTP) que la app del cliente pide por nuestro API.
 *
 * **Viven aparte de `message_log` a proposito.** El dedup y el cooldown de campanas miran
 * `message_log` por numero, sin importar de que campana venga: un OTP ahi adentro dejaria al
 * usuario fuera de toda publicidad por 30 dias sin que nadie se entere. Son dos cosas
 * distintas - una la pide el usuario, la otra se la mandamos nosotros - y se guardan distinto.
 *
 * Lo que si comparten es el techo de Meta: el limite diario es por cuenta y no distingue
 * categorias, asi que el freno del portfolio suma las dos tablas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();

            // E.164 sin el '+', igual que `contacts.phone` (52XXXXXXXXXX).
            $table->string('phone', 20);

            // Nunca el codigo en claro: si alguien lee la base, no puede entrar a ninguna cuenta.
            $table->string('code_hash');

            // Identificador que manda la app del cliente para amarrarlo a su propio registro.
            $table->string('reference', 100)->nullable();

            // pending -> sent -> verified | expired | failed
            $table->string('status', 20)->default('pending');

            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('verified_at')->nullable();

            // wamid de Meta y el motivo si no salio.
            $table->string('message_id')->nullable();
            $table->string('error_code', 20)->nullable();
            $table->string('error_message', 500)->nullable();

            $table->timestamps();

            // Verificar busca el ultimo vigente de un numero; el panel lista por fecha.
            $table->index(['phone', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
