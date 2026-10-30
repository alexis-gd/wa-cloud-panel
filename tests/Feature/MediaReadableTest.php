<?php

namespace Tests\Feature;

use App\Services\WhatsApp\MediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los archivos que guardamos tienen que poder leerse fuera de PHP.
 *
 * Nacen de un temporal (`tempnam()` o la subida de PHP), que se crea en **0600**, y `rename`
 * conserva el modo: quedaban legibles SOLO para www-data. El panel funcionaba igual porque los
 * sirve PHP, asi que nada fallaba a la vista - hasta que hubo que diagnosticar un audio y
 * ffmpeg no lo pudo abrir. Un archivo ilegible se veia exactamente igual que uno corrupto, y
 * eso mando la investigacion al lugar equivocado.
 */
class MediaReadableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Solo tiene sentido donde los permisos existen de verdad.
     *
     * Windows ignora `chmod`, asi que ahi la prueba pasaria siempre o fallaria siempre sin
     * decir nada del codigo. El servidor es Ubuntu; ahi es donde esto importa.
     */
    public function test_un_archivo_guardado_queda_legible(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Los permisos POSIX no aplican en Windows; se verifica en el servidor.');
        }

        $store = new MediaStore();

        $temporal = tempnam(sys_get_temp_dir(), 'prueba-medios-');
        file_put_contents($temporal, 'contenido');
        // Como nacen de verdad: solo el dueno.
        chmod($temporal, 0600);

        $ruta     = $store->guardarArchivo($temporal, 'audio/ogg', 1);
        $absoluta = $store->rutaAbsoluta($ruta);

        $this->assertNotNull($absoluta);
        $this->assertSame('0644', substr(sprintf('%o', fileperms($absoluta)), -4));

        @unlink($absoluta);
    }

    /** Se mueve, no se copia: el temporal no se queda ocupando disco. */
    public function test_el_temporal_no_sobrevive(): void
    {
        $store = new MediaStore();

        $temporal = tempnam(sys_get_temp_dir(), 'prueba-medios-');
        file_put_contents($temporal, 'contenido');

        $absoluta = $store->rutaAbsoluta($store->guardarArchivo($temporal, 'audio/ogg', 1));

        $this->assertFileDoesNotExist($temporal);

        @unlink($absoluta);
    }
}
