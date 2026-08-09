<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

/**
 * Imagen de encabezado de una plantilla, servida desde el propio panel.
 *
 * Meta devuelve la imagen de la plantilla como una URL de `scontent.whatsapp.net` que sirve
 * para la vista previa pero que **no entrega** al enviar: los mensajes salen `failed`. Por eso
 * el envío usa un archivo nuestro, publicado en `storage/app/public/templates/{plantilla}.{ext}`
 * y servido bajo `WA_MEDIA_BASE_URL`.
 *
 * El nombre del archivo es el de la plantilla, no el del archivo que subió el usuario: así el
 * envío lo encuentra sin guardar rutas en la BD.
 */
class TemplateImage
{
    /** Extensiones aceptadas, en orden de preferencia al resolver. */
    public const EXTENSIONS = ['jpg', 'png'];

    /** Tope de Meta para imágenes de plantilla (5 MB), en kilobytes para el validador. */
    public const MAX_KB = 5120;

    private const DIR = 'templates';

    /** Ruta absoluta del archivo local de la plantilla, o null si no hay ninguno. */
    public function path(string $templateName): ?string
    {
        foreach (self::EXTENSIONS as $ext) {
            foreach ($this->candidatePaths($templateName, $ext) as $path) {
                if (File::exists($path)) {
                    return $path;
                }
            }
        }

        return null;
    }

    public function exists(string $templateName): bool
    {
        return $this->path($templateName) !== null;
    }

    /**
     * URL pública del archivo local, o null si no hay archivo o falta `WA_MEDIA_BASE_URL`
     * (sin base no podemos armar una URL absoluta, y Meta las exige absolutas).
     */
    public function url(string $templateName): ?string
    {
        $path = $this->path($templateName);
        $base = rtrim((string) config('services.whatsapp.media_base_url', ''), '/');

        if ($path === null || $base === '') {
            return null;
        }

        return "{$base}/storage/" . self::DIR . "/{$templateName}." . pathinfo($path, PATHINFO_EXTENSION);
    }

    /**
     * Ruta relativa para mostrar la imagen dentro del panel. No sirve para Meta (exige URL
     * absoluta) pero funciona aunque `WA_MEDIA_BASE_URL` no esté configurada.
     */
    public function panelUrl(string $templateName): ?string
    {
        $path = $this->path($templateName);

        if ($path === null) {
            return null;
        }

        return '/storage/' . self::DIR . "/{$templateName}." . pathinfo($path, PATHINFO_EXTENSION);
    }

    /** Guarda la imagen y borra cualquier versión previa en otra extensión. */
    public function store(string $templateName, UploadedFile $file): void
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;

        $this->delete($templateName);

        $dir = dirname($this->pathFor($templateName, $ext));

        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $file->move($dir, "{$templateName}.{$ext}");
    }

    public function delete(string $templateName): void
    {
        foreach (self::EXTENSIONS as $ext) {
            foreach ($this->candidatePaths($templateName, $ext) as $path) {
                if (File::exists($path)) {
                    File::delete($path);
                }
            }
        }
    }

    /** Dónde se escribe: la ubicación canónica de Laravel, servida vía el symlink public/storage. */
    private function pathFor(string $templateName, string $ext): string
    {
        return storage_path('app/public/' . self::DIR . "/{$templateName}.{$ext}");
    }

    /**
     * Dónde se busca. Además de la canónica se mira `public/storage` porque no en todos los
     * entornos es un symlink (en Windows suele ser una carpeta real), y porque ahí quedaron
     * las imágenes que se subieron a mano por SSH antes de que existiera esta pantalla.
     *
     * @return array<int, string>
     */
    private function candidatePaths(string $templateName, string $ext): array
    {
        return [
            $this->pathFor($templateName, $ext),
            public_path('storage/' . self::DIR . "/{$templateName}.{$ext}"),
        ];
    }
}
