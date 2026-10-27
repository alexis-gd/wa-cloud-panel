<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archivos adjuntos en las conversaciones.
 *
 * Hasta ahora el webhook solo entendia texto y botones; una foto caia en el `default` del match
 * y se guardaba como un mensaje de texto VACIO. El operador veia una burbuja en blanco sin saber
 * que le habian mandado algo - en un negocio de prestamos eso son INE y comprobantes perdidos.
 *
 * El archivo NO se guarda aqui: aqui va la referencia. El binario vive en el disco `local`
 * (storage/app, fuera de la carpeta publica) y se sirve por una ruta autenticada, porque son
 * datos personales sensibles bajo la LFPDPPP.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `message_type` era un ENUM de tres valores. Hay que ampliarlo antes de poder guardar
        // una imagen, y en MySQL eso no se hace con Blueprint sin doctrine/dbal.
        DB::statement(
            "ALTER TABLE conversations MODIFY message_type "
            . "ENUM('text','button_reply','template','image','document','audio','video') "
            . "NOT NULL DEFAULT 'text'"
        );

        Schema::table('conversations', function (Blueprint $table) {
            // Identificador del archivo en Meta. Sirve para pedir la descarga y para reenviar
            // un archivo ya subido sin volver a subirlo.
            $table->string('media_id')->nullable()->after('message_type');

            // Ruta relativa dentro del disco `local`. Null mientras no se haya descargado.
            $table->string('media_path')->nullable()->after('media_id');

            $table->string('media_mime', 100)->nullable()->after('media_path');

            // Bytes. unsignedInteger llega a 4 GB, de sobra para el tope de 16 MB de WhatsApp.
            $table->unsignedInteger('media_size')->nullable()->after('media_mime');

            // Solo para documentos: el nombre con el que el contacto lo mando ("INE frente.pdf").
            $table->string('media_filename')->nullable()->after('media_size');

            /**
             * En que va la descarga.
             *
             * Existe porque la URL que da Meta caduca en minutos y la descarga ocurre en una
             * cola, DESPUES de responderle al webhook. Entre que llega el aviso y el archivo
             * esta en disco hay una ventana real, y el panel tiene que poder decir "descargando"
             * en vez de mostrar una burbuja rota.
             *
             * `failed` es terminal y se reintenta a mano: si la URL caduco, el archivo se
             * perdio del lado de Meta y no hay nada que reintentar.
             */
            $table->enum('media_status', ['pending', 'stored', 'failed'])
                ->nullable()
                ->after('media_filename');

            // Para el borrado por retencion: busca por fecha entre los que SI tienen archivo.
            $table->index(['media_status', 'created_at'], 'idx_conversations_media_status');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('idx_conversations_media_status');
            $table->dropColumn([
                'media_id',
                'media_path',
                'media_mime',
                'media_size',
                'media_filename',
                'media_status',
            ]);
        });

        DB::statement(
            "ALTER TABLE conversations MODIFY message_type "
            . "ENUM('text','button_reply','template') NOT NULL DEFAULT 'text'"
        );
    }
};
