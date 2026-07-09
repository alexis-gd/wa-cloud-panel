<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BuildGuidesCommand extends Command
{
    protected $signature   = 'guias:build';
    protected $description  = 'Genera el HTML estilizado de las guías desde su Markdown (fuente única)';

    /** Markdown fuente => HTML destino (relativo a public/). */
    private const GUIAS = [
        'docs/guias/guia-uso.md'  => 'guia/uso.html',
        'docs/guias/guia-meta.md' => 'guia/meta.html',
    ];

    public function handle(): int
    {
        $template = File::get(resource_path('guias/plantilla.html'));

        foreach (self::GUIAS as $src => $dest) {
            $this->buildOne(base_path($src), public_path($dest), $template);
            $this->info("Generado: public/{$dest}");
        }

        return self::SUCCESS;
    }

    private function buildOne(string $mdPath, string $outPath, string $template): void
    {
        $md = File::get($mdPath);

        // El título es el primer "# " y el índice se reemplaza por el menú lateral.
        $title = 'Guía de uso - Panel Prestamaz';
        if (preg_match('/^#\s+(.+)$/m', $md, $m)) {
            $title = trim($m[1]);
        }
        $md = $this->stripIndice($md);

        $html = Str::markdown($md, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        [$html, $toc] = $this->addAnchorsAndToc($html);
        $html = $this->wrapTables($html);

        $out = strtr($template, [
            '{{TITLE}}'   => e($title),
            '{{TOC}}'     => $toc,
            '{{CONTENT}}' => $html,
        ]);

        File::ensureDirectoryExists(dirname($outPath));
        File::put($outPath, $out);
    }

    /** Quita el bloque "## Índice ... ---" (lo reemplaza el menú lateral). */
    private function stripIndice(string $md): string
    {
        return preg_replace('/^##\s+Índice\b.*?^---\s*$/ms', '', $md, 1);
    }

    /**
     * Agrega id a cada <h2>/<h3> con slug estilo GitHub (para que enlacen los #anchors
     * del propio Markdown) y construye la lista del menú lateral con los <h2>.
     *
     * @return array{0:string,1:string}
     */
    private function addAnchorsAndToc(string $html): array
    {
        $toc = [];

        $html = preg_replace_callback(
            '/<h([23])>(.*?)<\/h\1>/s',
            function (array $m) use (&$toc) {
                $level = $m[1];
                $text  = trim(strip_tags($m[2]));
                $slug  = $this->slug($text);

                if ($level === '2') {
                    $toc[] = '<li><a href="#' . $slug . '">' . $text . '</a></li>';
                }

                return "<h{$level} id=\"{$slug}\">{$m[2]}</h{$level}>";
            },
            $html
        );

        return [$html, implode("\n        ", $toc)];
    }

    /** Slug estilo GitHub: minúsculas, sin acentos, sin signos, espacios a guión. */
    private function slug(string $text): string
    {
        $t = mb_strtolower($text, 'UTF-8');
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
        $t = preg_replace('/[^a-z0-9 -]/', '', $t);
        $t = preg_replace('/\s+/', '-', trim($t));

        return preg_replace('/-+/', '-', $t);
    }

    /** Envuelve cada <table> en un contenedor con scroll horizontal. */
    private function wrapTables(string $html): string
    {
        return preg_replace('/<table>(.*?)<\/table>/s', '<div class="table-wrap"><table>$1</table></div>', $html);
    }
}
