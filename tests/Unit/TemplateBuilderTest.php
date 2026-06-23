<?php

namespace Tests\Unit;

use App\Models\WaTemplate;
use App\Services\WhatsApp\TemplateBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemplateBuilderTest extends TestCase
{
    use RefreshDatabase;

    private TemplateBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new TemplateBuilder();
    }

    // ── Sin header ────────────────────────────────────────────────────────────

    public function test_build_sin_header_no_incluye_componente_header(): void
    {
        WaTemplate::factory()->create([
            'name'             => 'hello_world',
            'header_type'      => null,
            'header_image_url' => null,
        ]);

        $payload = $this->builder->build('521234567890', 'hello_world', 'es_MX');

        $components = $payload['template']['components'] ?? [];
        $headerComponents = array_filter($components, fn($c) => $c['type'] === 'header');

        $this->assertEmpty($headerComponents);
    }

    public function test_build_sin_plantilla_en_bd_no_incluye_componentes(): void
    {
        // No hay plantilla en BD — build no debe explotar
        $payload = $this->builder->build('521234567890', 'plantilla_inexistente', 'es_MX');

        $this->assertArrayNotHasKey('components', $payload['template']);
    }

    // ── Con IMAGE header ──────────────────────────────────────────────────────

    public function test_build_con_image_header_incluye_componente_header_tipo_image(): void
    {
        // Nombre sin archivo local para aislar del WA_MEDIA_BASE_URL del entorno
        WaTemplate::factory()->create([
            'name'             => 'plantilla_imagen_remota',
            'header_type'      => 'IMAGE',
            'header_image_url' => 'https://cdn.example.com/header.jpg',
        ]);

        $payload = $this->builder->build('521234567890', 'plantilla_imagen_remota', 'es_MX');

        $components = $payload['template']['components'];
        $header = collect($components)->firstWhere('type', 'header');

        $this->assertNotNull($header);
        $this->assertSame('image', $header['parameters'][0]['type']);
        $this->assertSame('https://cdn.example.com/header.jpg', $header['parameters'][0]['image']['link']);
    }

    public function test_build_image_header_sin_url_no_incluye_componente_header(): void
    {
        WaTemplate::factory()->create([
            'name'             => 'template_sin_url',
            'header_type'      => 'IMAGE',
            'header_image_url' => null,
        ]);

        $payload = $this->builder->build('521234567890', 'template_sin_url', 'es_MX');

        $components = $payload['template']['components'] ?? [];
        $headerComponents = array_filter($components, fn($c) => $c['type'] === 'header');

        $this->assertEmpty($headerComponents);
    }

    // ── Con body vars ─────────────────────────────────────────────────────────

    public function test_build_con_body_vars_incluye_componente_body_con_parametros(): void
    {
        WaTemplate::factory()->create([
            'name'        => 'plantilla_con_vars',
            'body_text'   => 'Hola {{1}}, tu préstamo de {{2}} está listo',
            'header_type' => null,
        ]);

        $payload = $this->builder->build(
            '521234567890',
            'plantilla_con_vars',
            'es_MX',
            ['Juan', '$10,000']
        );

        $components = $payload['template']['components'];
        $body = collect($components)->firstWhere('type', 'body');

        $this->assertNotNull($body);
        $this->assertCount(2, $body['parameters']);
        $this->assertSame('text', $body['parameters'][0]['type']);
        $this->assertSame('Juan', $body['parameters'][0]['text']);
        $this->assertSame('$10,000', $body['parameters'][1]['text']);
    }

    public function test_build_sin_body_vars_no_incluye_componente_body(): void
    {
        WaTemplate::factory()->create([
            'name'        => 'plantilla_sin_vars',
            'header_type' => null,
        ]);

        $payload = $this->builder->build('521234567890', 'plantilla_sin_vars', 'es_MX', []);

        $components = $payload['template']['components'] ?? [];
        $bodyComponents = array_filter($components, fn($c) => $c['type'] === 'body');

        $this->assertEmpty($bodyComponents);
    }

    // ── Resolución de URL local (WA_MEDIA_BASE_URL) ──────────────────────────

    public function test_build_image_header_usa_url_local_cuando_existe_archivo_y_media_base_url(): void
    {
        config(['services.whatsapp.media_base_url' => 'https://ngrok.example.io']);

        WaTemplate::factory()->create([
            'name'             => 'prestamaz_interes_v1',
            'header_type'      => 'IMAGE',
            'header_image_url' => 'https://scontent.whatsapp.net/old-cdn-url.jpg',
        ]);

        $payload = $this->builder->build('521234567890', 'prestamaz_interes_v1', 'es_MX');

        $header = collect($payload['template']['components'])->firstWhere('type', 'header');

        $this->assertSame(
            'https://ngrok.example.io/storage/templates/prestamaz_interes_v1.jpg',
            $header['parameters'][0]['image']['link']
        );
    }

    public function test_build_image_header_fallback_a_db_cuando_no_hay_archivo_local(): void
    {
        config(['services.whatsapp.media_base_url' => 'https://ngrok.example.io']);

        WaTemplate::factory()->create([
            'name'             => 'plantilla_sin_archivo_local',
            'header_type'      => 'IMAGE',
            'header_image_url' => 'https://cdn.example.com/header.jpg',
        ]);

        $payload = $this->builder->build('521234567890', 'plantilla_sin_archivo_local', 'es_MX');

        $header = collect($payload['template']['components'])->firstWhere('type', 'header');

        $this->assertSame(
            'https://cdn.example.com/header.jpg',
            $header['parameters'][0]['image']['link']
        );
    }

    // ── Estructura base del payload ───────────────────────────────────────────

    public function test_build_siempre_incluye_campos_obligatorios(): void
    {
        $payload = $this->builder->build('521234567890', 'hello_world', 'es_MX');

        $this->assertSame('whatsapp', $payload['messaging_product']);
        $this->assertSame('521234567890', $payload['to']);
        $this->assertSame('template', $payload['type']);
        $this->assertSame('hello_world', $payload['template']['name']);
        $this->assertSame('es_MX', $payload['template']['language']['code']);
    }
}
