<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\PhoneNumber;
use App\Models\WaTemplate;
use App\Services\WhatsApp\TemplateImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * La imagen de encabezado vive en disco con el nombre de la plantilla, porque la URL que Meta
 * guarda al sincronizar es de vista previa y no se entrega. Antes solo se podía poner por SSH.
 */
class TemplateImageTest extends TestCase
{
    use RefreshDatabase;

    private TemplateImage $image;

    protected function setUp(): void
    {
        parent::setUp();
        $this->image = new TemplateImage();
    }

    protected function tearDown(): void
    {
        // Los archivos son reales (el envío los lee del disco), así que se limpian a mano.
        foreach (WaTemplate::pluck('name') as $name) {
            $this->image->delete($name);
        }

        parent::tearDown();
    }

    private function templateWithImageHeader(string $name = 'promo_v1'): WaTemplate
    {
        return WaTemplate::factory()->create([
            'name'        => $name,
            'header_type' => 'IMAGE',
            'status'      => 'approved',
            'is_active'   => true,
        ]);
    }

    public function test_admin_sube_la_imagen_y_queda_con_el_nombre_de_la_plantilla(): void
    {
        $tpl = $this->templateWithImageHeader();

        $this->actingAsAdmin()
             ->postJson("/api/templates/{$tpl->id}/image", [
                 'image' => UploadedFile::fake()->image('lo-que-sea.jpg'),
             ])
             ->assertStatus(200)
             ->assertJsonPath('data.needs_image', false);

        $this->assertNotNull($this->image->path('promo_v1'));
        $this->assertStringEndsWith('promo_v1.jpg', $this->image->path('promo_v1'));
    }

    public function test_acepta_png(): void
    {
        $tpl = $this->templateWithImageHeader('promo_png');

        $this->actingAsAdmin()
             ->postJson("/api/templates/{$tpl->id}/image", [
                 'image' => UploadedFile::fake()->image('banner.png'),
             ])
             ->assertStatus(200);

        $this->assertStringEndsWith('promo_png.png', $this->image->path('promo_png'));
    }

    public function test_rechaza_formatos_que_meta_no_acepta(): void
    {
        $tpl = $this->templateWithImageHeader();

        $this->actingAsAdmin()
             ->postJson("/api/templates/{$tpl->id}/image", [
                 'image' => UploadedFile::fake()->create('documento.pdf', 10, 'application/pdf'),
             ])
             ->assertStatus(422)
             ->assertJsonValidationErrors('image');

        $this->assertNull($this->image->path('promo_v1'));
    }

    public function test_rechaza_imagenes_de_mas_de_5_mb(): void
    {
        $tpl = $this->templateWithImageHeader();

        $this->actingAsAdmin()
             ->postJson("/api/templates/{$tpl->id}/image", [
                 'image' => UploadedFile::fake()->create('enorme.jpg', TemplateImage::MAX_KB + 1, 'image/jpeg'),
             ])
             ->assertStatus(422)
             ->assertJsonValidationErrors('image');
    }

    /**
     * Si el archivo supera `upload_max_filesize`, PHP lo descarta y Laravel solo ve que "falta"
     * la imagen. El mensaje debe decir el motivo real, no "Elige una imagen".
     */
    public function test_avisa_cuando_el_servidor_corta_la_subida_por_tamano(): void
    {
        $tpl  = $this->templateWithImageHeader();
        $temp = tempnam(sys_get_temp_dir(), 'img');

        $descartadoPorPhp = new UploadedFile($temp, 'enorme.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $response = $this->actingAsAdmin()
             ->post("/api/templates/{$tpl->id}/image", ['image' => $descartadoPorPhp], ['Accept' => 'application/json'])
             ->assertStatus(422)
             ->assertJsonPath('code', 'UPLOAD_LIMIT_EXCEEDED');

        $this->assertStringContainsString('límite de subida del servidor', $response->json('message'));

        @unlink($temp);
    }

    public function test_rechaza_plantillas_sin_encabezado_de_imagen(): void
    {
        $tpl = WaTemplate::factory()->create(['header_type' => 'TEXT']);

        $this->actingAsAdmin()
             ->postJson("/api/templates/{$tpl->id}/image", [
                 'image' => UploadedFile::fake()->image('banner.jpg'),
             ])
             ->assertStatus(422)
             ->assertJsonPath('code', 'TEMPLATE_WITHOUT_IMAGE_HEADER');
    }

    public function test_operator_no_puede_subir_imagen(): void
    {
        $tpl = $this->templateWithImageHeader();

        $this->actingAsOperator()
             ->postJson("/api/templates/{$tpl->id}/image", [
                 'image' => UploadedFile::fake()->image('banner.jpg'),
             ])
             ->assertStatus(403);
    }

    public function test_reemplazar_la_imagen_no_deja_la_extension_vieja(): void
    {
        $tpl = $this->templateWithImageHeader('promo_reemplazo');

        $this->actingAsAdmin()->postJson("/api/templates/{$tpl->id}/image", [
            'image' => UploadedFile::fake()->image('primera.jpg'),
        ])->assertStatus(200);

        $this->actingAsAdmin()->postJson("/api/templates/{$tpl->id}/image", [
            'image' => UploadedFile::fake()->image('segunda.png'),
        ])->assertStatus(200);

        $this->assertStringEndsWith('promo_reemplazo.png', $this->image->path('promo_reemplazo'));
        $this->assertFileDoesNotExist(storage_path('app/public/templates/promo_reemplazo.jpg'));
    }

    public function test_admin_puede_quitar_la_imagen(): void
    {
        $tpl = $this->templateWithImageHeader('promo_borrar');

        $this->actingAsAdmin()->postJson("/api/templates/{$tpl->id}/image", [
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertStatus(200);

        $this->actingAsAdmin()
             ->deleteJson("/api/templates/{$tpl->id}/image")
             ->assertStatus(200)
             ->assertJsonPath('data.needs_image', true);

        $this->assertNull($this->image->path('promo_borrar'));
    }

    // ── Red de seguridad: sin imagen no se puede lanzar la campaña ───────────

    public function test_no_deja_crear_campana_con_plantilla_que_le_falta_la_imagen(): void
    {
        PhoneNumber::factory()->create(['is_active' => true]);
        $tpl = $this->templateWithImageHeader('promo_sin_imagen');

        $this->actingAsAdmin()
             ->postJson('/api/campaigns', [
                 'name'          => 'Campaña de prueba',
                 'channel'       => 'whatsapp',
                 'template_name' => $tpl->name,
                 'language_code' => 'es_MX',
                 'recipients'    => 'all',
             ])
             ->assertStatus(422)
             ->assertJsonPath('code', 'TEMPLATE_IMAGE_MISSING');

        $this->assertEquals(0, Campaign::count());
    }

    public function test_deja_crear_la_campana_una_vez_subida_la_imagen(): void
    {
        PhoneNumber::factory()->create(['is_active' => true]);
        $tpl = $this->templateWithImageHeader('promo_con_imagen');

        $this->actingAsAdmin()->postJson("/api/templates/{$tpl->id}/image", [
            'image' => UploadedFile::fake()->image('banner.jpg'),
        ])->assertStatus(200);

        $this->actingAsAdmin()
             ->postJson('/api/campaigns', [
                 'name'          => 'Campaña de prueba',
                 'channel'       => 'whatsapp',
                 'template_name' => $tpl->name,
                 'language_code' => 'es_MX',
                 'recipients'    => 'all',
             ])
             ->assertStatus(201);
    }

    public function test_no_deja_probar_una_plantilla_a_la_que_le_falta_la_imagen(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        PhoneNumber::factory()->create(['is_active' => true]);
        \App\Models\Contact::factory()->create(['phone' => '529231311146', 'status' => 'active']);
        $tpl = $this->templateWithImageHeader('promo_sin_img_prueba');

        $this->actingAsAdmin()
             ->postJson('/api/templates/send-test', [
                 'template_name' => $tpl->name,
                 'language_code' => 'es_MX',
                 'to'            => '529231311146',
             ])
             ->assertStatus(422)
             ->assertJsonPath('code', 'TEMPLATE_IMAGE_MISSING');

        \Illuminate\Support\Facades\Http::assertNothingSent();
    }

    /** El envío debe preferir el archivo local sobre la URL del CDN de Meta, sea jpg o png. */
    public function test_el_envio_usa_la_imagen_local_tambien_en_png(): void
    {
        config(['services.whatsapp.media_base_url' => 'https://panel.example.com']);
        $tpl = $this->templateWithImageHeader('promo_png_envio');
        $tpl->update(['header_image_url' => 'https://scontent.whatsapp.net/preview.jpg']);

        $this->actingAsAdmin()->postJson("/api/templates/{$tpl->id}/image", [
            'image' => UploadedFile::fake()->image('banner.png'),
        ])->assertStatus(200);

        $payload = app(\App\Services\WhatsApp\TemplateBuilder::class)
            ->build('521234567890', 'promo_png_envio', 'es_MX');

        $header = collect($payload['template']['components'])->firstWhere('type', 'header');

        $this->assertSame(
            'https://panel.example.com/storage/templates/promo_png_envio.png',
            $header['parameters'][0]['image']['link']
        );
    }
}
