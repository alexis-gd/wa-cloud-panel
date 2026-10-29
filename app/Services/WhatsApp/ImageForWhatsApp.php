<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;

/**
 * Deja una imagen del operador en algo que Meta acepte.
 *
 * **Por que esto no es una optimizacion, es un requisito.** El tope de Meta para una imagen es
 * de 5 MB y solo admite JPEG y PNG. Una foto de un telefono actual pesa entre 8 y 12 MB y
 * puede venir en WebP. Sin pasar por aqui, el envio simplemente falla.
 *
 * **Por que no rechazar en vez de procesar.** Ya hay un precedente en el proyecto que rechaza
 * (`TemplateImage`), y ahi tiene sentido: la imagen de una plantilla la prepara soporte una
 * vez. Aqui no: decirle al operador "no puedes mandar la foto que tienes" a media conversacion
 * con un cliente es exactamente el tipo de callejon sin salida que el sistema debe evitar.
 *
 * **Por que no recomprimir siempre.** WhatsApp ya comprime en el telefono antes de mandar, y
 * volver a codificar un JPEG pierde calidad cada vez. Una imagen que ya cabe y ya mide poco se
 * manda tal cual.
 *
 * **El riesgo de memoria y como se acota.** Descomprimir una imagen ocupa ancho x alto x 4
 * bytes, sin importar lo que pese el archivo: un PNG de 2 MB puede ser de 20000x20000 y pedir
 * 1.6 GB al descomprimirse. Por eso las medidas se leen con `getimagesize()`, que lee solo el
 * encabezado, y se rechaza ANTES de decodificar nada.
 */
class ImageForWhatsApp
{
    /** Tope de Meta para imagenes. */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Lo unico que Meta acepta como imagen. */
    public const MIMES_META = ['image/jpeg', 'image/png'];

    /** Lo que aceptamos del operador. El WebP se convierte. */
    public const MIMES_ENTRADA = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Lado mas largo al que se reduce. 1600 px es lo que usa WhatsApp: se ve bien en cualquier
     * telefono y en pantalla completa, y deja el archivo en cientos de KB.
     */
    private const LADO_MAX = 1600;

    /** 82 es donde el ojo deja de notar la diferencia y el archivo todavia baja mucho. */
    private const CALIDAD = 82;

    /**
     * Megapixeles que aceptamos descomprimir. 80 MP cubre cualquier camara de telefono (la mas
     * alta hoy ronda los 50) y acota la memoria a ~320 MB en el peor caso.
     */
    private const MAX_PIXELES = 80_000_000;

    /** Debajo de esto no vale la pena recodificar: ya cabe y ya pesa poco. */
    private const SIN_TOCAR_BYTES = 2 * 1024 * 1024;

    /**
     * @return array{ok: bool, path: ?string, mime: ?string, temporal: bool, error: ?string}
     *         `temporal` dice si hay que borrar el archivo despues: es false cuando se devuelve
     *         el original sin tocar, que no nos toca borrar.
     */
    public function preparar(string $ruta, string $mime): array
    {
        if (! in_array($mime, self::MIMES_ENTRADA, true)) {
            return $this->falla('Solo se pueden mandar imagenes JPG, PNG o WebP.');
        }

        if (! extension_loaded('gd')) {
            Log::error('ImageForWhatsApp: falta la extension GD en el servidor');

            return $this->falla('El servidor no puede procesar imagenes. Avisa a soporte.');
        }

        // Solo el encabezado: aqui todavia no se descomprime nada.
        $medidas = @getimagesize($ruta);

        if ($medidas === false) {
            return $this->falla('El archivo no parece una imagen valida.');
        }

        [$ancho, $alto] = $medidas;

        if ($ancho * $alto > self::MAX_PIXELES) {
            return $this->falla('La imagen tiene demasiada resolucion para procesarla.');
        }

        $pesa = filesize($ruta);
        $cabe = $pesa !== false && $pesa <= self::SIN_TOCAR_BYTES;
        $mide = max($ancho, $alto) <= self::LADO_MAX;

        // Ya cabe, ya mide poco y Meta entiende el formato: mandarla tal cual conserva la
        // calidad original. Recodificar aqui solo la empeoraria.
        if ($cabe && $mide && in_array($mime, self::MIMES_META, true)) {
            return ['ok' => true, 'path' => $ruta, 'mime' => $mime, 'temporal' => false, 'error' => null];
        }

        return $this->recodificar($ruta, $mime, $ancho, $alto);
    }

    /**
     * Reduce y vuelve a guardar como JPEG.
     *
     * Siempre JPEG, incluso si entro un PNG: una foto en PNG pesa varias veces lo mismo sin
     * verse mejor, y lo que manda el operador son fotos. El PNG pequeno ya se fue por el
     * camino de arriba sin tocarse, que es el caso donde el PNG si importa (una captura, un
     * logo con transparencia).
     */
    private function recodificar(string $ruta, string $mime, int $ancho, int $alto): array
    {
        $origen = $this->abrir($ruta, $mime);

        if ($origen === null) {
            return $this->falla('No se pudo leer la imagen.');
        }

        try {
            $escala = min(1, self::LADO_MAX / max($ancho, $alto));
            $nuevoAncho = max(1, (int) round($ancho * $escala));
            $nuevoAlto  = max(1, (int) round($alto * $escala));

            $destino = imagecreatetruecolor($nuevoAncho, $nuevoAlto);

            // Un PNG con transparencia sobre un lienzo sin fondo sale con manchas negras al
            // pasarlo a JPEG. Se pinta blanco primero, que es lo que espera quien lo ve.
            $blanco = imagecolorallocate($destino, 255, 255, 255);
            imagefilledrectangle($destino, 0, 0, $nuevoAncho, $nuevoAlto, $blanco);

            imagecopyresampled($destino, $origen, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

            $salida = tempnam(sys_get_temp_dir(), 'wa-img-');

            if ($salida === false || ! imagejpeg($destino, $salida, self::CALIDAD)) {
                if ($salida !== false) {
                    @unlink($salida);
                }

                return $this->falla('No se pudo preparar la imagen para enviarla.');
            }
        } finally {
            imagedestroy($origen);

            if (isset($destino)) {
                imagedestroy($destino);
            }
        }

        // Cinturon y tirantes: con 1600 px de lado y calidad 82 nunca deberia pasar, pero si
        // pasara, Meta rechazaria el envio y el operador no sabria por que.
        if (filesize($salida) > self::MAX_BYTES) {
            @unlink($salida);

            return $this->falla('La imagen sigue siendo demasiado pesada despues de reducirla.');
        }

        return ['ok' => true, 'path' => $salida, 'mime' => 'image/jpeg', 'temporal' => true, 'error' => null];
    }

    private function abrir(string $ruta, string $mime): ?\GdImage
    {
        $imagen = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($ruta),
            'image/png'  => @imagecreatefrompng($ruta),
            'image/webp' => @imagecreatefromwebp($ruta),
            default      => false,
        };

        return $imagen === false ? null : $imagen;
    }

    private function falla(string $error): array
    {
        return ['ok' => false, 'path' => null, 'mime' => null, 'temporal' => false, 'error' => $error];
    }
}
