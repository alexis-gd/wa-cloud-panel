<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\UploadedFile;

/**
 * Decide que hacer con el archivo que el operador quiere mandar.
 *
 * Meta no tiene un tope unico: son cuatro, muy distintos entre si, y cada tipo admite formatos
 * distintos. Un archivo que para un tipo es minusculo para otro es imposible.
 *
 * | Tipo       | Tope Meta | Que hacemos si se pasa          |
 * |------------|-----------|---------------------------------|
 * | Imagen     | 5 MB      | **reducirla** (ImageForWhatsApp)|
 * | Audio      | 16 MB     | avisar                          |
 * | Video      | 16 MB     | avisar                          |
 * | Documento  | 100 MB    | avisar                          |
 *
 * **Por que la imagen se arregla y lo demas no.** Una foto se puede reducir sin que el
 * destinatario note la diferencia: WhatsApp hace exactamente eso. Un video no: recomprimirlo
 * exige transcodificar (ffmpeg, minutos de CPU por archivo), y un PDF solo se encoge
 * rasterizandolo, o sea destruyendo el texto seleccionable que es justo lo que lo hace util.
 * Con esos, lo honesto es decirlo claro y a tiempo, no fingir que se puede.
 *
 * **El tope de verdad suele ser otro.** Los 100 MB de documento son teoricos: la subida pasa
 * antes por nginx y por PHP, que en este servidor estan en 20 MB. Por eso el mensaje nombra el
 * limite que de verdad freno el archivo, no el de Meta.
 */
class OutboundAttachment
{
    /** Topes de Meta por tipo de mensaje, en bytes. */
    private const TOPES = [
        'image'    => 5 * 1024 * 1024,
        'audio'    => 16 * 1024 * 1024,
        'video'    => 16 * 1024 * 1024,
        'document' => 100 * 1024 * 1024,
    ];

    /**
     * Que tipo de mensaje de WhatsApp le corresponde a cada formato.
     *
     * Los de imagen no estan aqui: los resuelve `ImageForWhatsApp`, que ademas los convierte.
     */
    private const TIPOS = [
        'audio/aac'      => 'audio',
        'audio/amr'      => 'audio',
        'audio/mpeg'     => 'audio',
        'audio/mp4'      => 'audio',
        'audio/x-m4a'    => 'audio',
        // La familia Ogg: un mismo `.ogg` se identifica de varias formas segun lo que lleve
        // dentro. Una nota de voz de WhatsApp es Opus dentro de Ogg, y el sistema la puede
        // reportar con cualquiera de estos nombres. Sin ellos, reenviar una nota de voz que
        // el contacto acababa de mandar se rechazaba.
        'audio/ogg'      => 'audio',
        'audio/x-ogg'    => 'audio',
        'audio/opus'     => 'audio',
        'audio/x-opus+ogg' => 'audio',
        'application/ogg'  => 'audio',
        'video/mp4'      => 'video',
        'video/3gpp'     => 'video',
        'application/pdf'                                                           => 'document',
        'application/msword'                                                        => 'document',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'document',
        'application/vnd.ms-excel'                                                  => 'document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'document',
        'application/vnd.ms-powerpoint'                                             => 'document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'document',
        'text/plain'                                                                => 'document',
    ];

    /**
     * Lo que le decimos a Meta cuando el sistema nombro el formato de otra manera.
     *
     * Meta acepta exactamente `audio/ogg`; los demas nombres de la familia son el mismo
     * archivo visto por otro catalogo. Subirlo con el nombre que adivino el sistema seria que
     * Meta lo rechace por un detalle de nomenclatura.
     */
    private const NORMALIZADOS = [
        'audio/x-ogg'      => 'audio/ogg',
        'audio/opus'       => 'audio/ogg',
        'audio/x-opus+ogg' => 'audio/ogg',
        'application/ogg'  => 'audio/ogg',
        'audio/x-m4a'      => 'audio/mp4',
    ];

    /** Nombre en lenguaje de operador, para los mensajes de error. */
    private const NOMBRES = [
        'image'    => 'La imagen',
        'audio'    => 'El audio',
        'video'    => 'El video',
        'document' => 'El documento',
    ];

    public function __construct(private readonly ImageForWhatsApp $imagenes)
    {
    }

    /** Todos los formatos que el panel acepta, para la validacion de la peticion. */
    public static function mimesAceptados(): array
    {
        return array_merge(ImageForWhatsApp::MIMES_ENTRADA, array_keys(self::TIPOS));
    }

    /**
     * @return array{ok: bool, path: ?string, mime: ?string, tipo: ?string, temporal: bool, error: ?string}
     */
    public function preparar(UploadedFile $archivo): array
    {
        $mime = $archivo->getMimeType();

        if (in_array($mime, ImageForWhatsApp::MIMES_ENTRADA, true)) {
            $listo = $this->imagenes->preparar($archivo->getRealPath(), $mime);

            return $listo + ['tipo' => 'image'];
        }

        $tipo = self::TIPOS[$mime] ?? null;

        if ($tipo === null) {
            return $this->falla('Ese tipo de archivo no se puede mandar por WhatsApp.');
        }

        $pesa = $archivo->getSize();

        if ($pesa !== false && $pesa > self::TOPES[$tipo]) {
            $limite = (int) (self::TOPES[$tipo] / 1024 / 1024);

            return $this->falla(
                self::NOMBRES[$tipo] . " pasa del máximo que acepta WhatsApp ({$limite} MB)."
            );
        }

        return [
            'ok'       => true,
            'path'     => $archivo->getRealPath(),
            'mime'     => self::NORMALIZADOS[$mime] ?? $mime,
            'tipo'     => $tipo,
            // El temporal de la subida lo limpia PHP al terminar la peticion; no nos toca.
            'temporal' => false,
            'error'    => null,
        ];
    }

    private function falla(string $error): array
    {
        return ['ok' => false, 'path' => null, 'mime' => null, 'tipo' => null, 'temporal' => false, 'error' => $error];
    }
}
