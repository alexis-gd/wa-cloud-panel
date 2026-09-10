<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * El Excel lo abre el equipo de Prestamaz, no un programador: los estados internos
 * (`active`, `delivered`, `whatsapp`) tienen que salir en español.
 *
 * Los tests que ya existían solo verificaban el content-type, así que la hoja podía traer
 * cualquier cosa adentro. Estos leen el archivo generado y revisan las celdas.
 */
class ExportLabelsTest extends TestCase
{
    use RefreshDatabase;

    /** Descarga el export y devuelve la hoja como matriz de filas. */
    private function hojaDe(string $ruta): array
    {
        $response = $this->actingAsAdmin()->get($ruta);
        $response->assertStatus(200);

        $archivo = tempnam(sys_get_temp_dir(), 'export') . '.xlsx';
        file_put_contents($archivo, $response->streamedContent());

        $filas = IOFactory::load($archivo)->getActiveSheet()->toArray();
        unlink($archivo);

        return $filas;
    }

    public function test_el_excel_de_contactos_trae_el_estado_en_espanol(): void
    {
        Contact::factory()->create(['status' => 'active',      'source' => 'excel']);
        Contact::factory()->create(['status' => 'opted_out',   'source' => 'manual']);
        Contact::factory()->create(['status' => 'unreachable', 'source' => 'api']);

        $filas = $this->hojaDe('/api/export/contacts');
        $texto = json_encode($filas, JSON_UNESCAPED_UNICODE);

        foreach (['Activo', 'Baja', 'Inalcanzable'] as $esperado) {
            $this->assertStringContainsString($esperado, $texto);
        }
        foreach (['active', 'opted_out', 'unreachable'] as $crudo) {
            $this->assertStringNotContainsString("\"{$crudo}\"", $texto);
        }
    }

    public function test_el_excel_de_contactos_trae_la_columna_de_etiquetas(): void
    {
        $conTags = \App\Models\Contact::factory()->create(['phone' => '526692522844']);
        $conTags->tags()->attach([
            \App\Models\Tag::create(['name' => 'VIP'])->id,
            \App\Models\Tag::create(['name' => 'Mazatlan'])->id,
        ]);
        \App\Models\Contact::factory()->create(['phone' => '526692522845']);   // sin etiquetas

        $filas = $this->hojaDe('/api/export/contacts');

        $this->assertContains('Etiquetas', $filas[0]);

        $columna = array_search('Etiquetas', $filas[0], true);
        $celdas  = array_column(array_slice($filas, 1), $columna);

        // Separadas por coma: el mismo formato que lee el importador, para poder volver a
        // subir el archivo exportado y re-etiquetar.
        $conEtiquetas = array_values(array_filter($celdas));
        $this->assertCount(1, $conEtiquetas);
        $this->assertStringContainsString('VIP', $conEtiquetas[0]);
        $this->assertStringContainsString('Mazatlan', $conEtiquetas[0]);
        $this->assertStringContainsString(',', $conEtiquetas[0]);
    }

    public function test_el_excel_de_contactos_trae_la_fuente_en_espanol(): void
    {
        Contact::factory()->create(['source' => 'excel']);
        Contact::factory()->create(['source' => 'manual']);

        $texto = json_encode($this->hojaDe('/api/export/contacts'), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Manual', $texto);
        $this->assertStringNotContainsString('"manual"', $texto);
    }

    public function test_la_columna_de_pospuesto_ya_no_dice_snooze(): void
    {
        Contact::factory()->create();

        $encabezado = $this->hojaDe('/api/export/contacts')[0];

        $this->assertContains('Pospuesto hasta', $encabezado);
        $this->assertNotContains('Snooze hasta', $encabezado);
    }

    public function test_el_excel_de_mensajes_trae_estado_y_canal_en_espanol(): void
    {
        $phone = PhoneNumber::factory()->create();
        MessageLog::factory()->create(['phone_number_id' => $phone->id, 'status' => 'delivered', 'channel' => 'whatsapp']);
        MessageLog::factory()->create(['phone_number_id' => $phone->id, 'status' => 'failed',    'channel' => 'sms']);

        $filas      = $this->hojaDe('/api/export/messages');
        $encabezado = $filas[0];
        $texto      = json_encode($filas, JSON_UNESCAPED_UNICODE);

        $this->assertContains('Canal', $encabezado);
        $this->assertStringContainsString('Entregado', $texto);
        $this->assertStringContainsString('Fallido', $texto);
        $this->assertStringContainsString('WhatsApp', $texto);
        $this->assertStringContainsString('SMS', $texto);
        $this->assertStringNotContainsString('"delivered"', $texto);
        $this->assertStringNotContainsString('"failed"', $texto);
    }

    public function test_las_fechas_del_excel_van_en_hora_de_mexico(): void
    {
        $phone = PhoneNumber::factory()->create();

        // 03:00 UTC del día 2 son las 21:00 del día 1 en México: si no se convierte, la fila
        // aparece con la fecha del día siguiente y a una hora que nunca se envió.
        MessageLog::factory()->create([
            'phone_number_id' => $phone->id,
            'sent_at'         => '2026-08-02 03:00:00',
        ]);

        $texto = json_encode($this->hojaDe('/api/export/messages'), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('2026-08-01 21:00:00', $texto);
        $this->assertStringNotContainsString('2026-08-02 03:00:00', $texto);
    }

    // ── Columna "Motivo": el Excel decia solo "Fallido" y el cliente preguntaba por que ──

    public function test_el_excel_de_mensajes_dice_que_respondio_meta_cuando_falla_whatsapp(): void
    {
        $phone = PhoneNumber::factory()->create();

        MessageLog::factory()->create([
            'phone_number_id'      => $phone->id,
            'channel'              => 'whatsapp',
            'status'               => 'failed',
            'delivery_error_code'  => 131026,
            'delivery_error_title' => 'Message undeliverable',
        ]);

        $texto = json_encode($this->hojaDe('/api/export/messages'), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Motivo', $texto);
        $this->assertStringContainsString('Meta respondió: El número no tiene WhatsApp', $texto);
    }

    public function test_el_excel_de_mensajes_no_le_echa_la_culpa_a_meta_de_un_sms(): void
    {
        $phone = PhoneNumber::factory()->create();

        MessageLog::factory()->create([
            'phone_number_id' => $phone->id,
            'channel'         => 'sms',
            'status'          => 'failed',
            'error_message'   => 'El gateway reportó el envío como fallido (sin detalle)',
        ]);

        $texto = json_encode($this->hojaDe('/api/export/messages'), JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('El gateway de SMS respondió:', $texto);
        $this->assertStringNotContainsString('Meta respondió', $texto);
    }

    public function test_el_excel_de_mensajes_deja_el_motivo_vacio_cuando_el_mensaje_si_llego(): void
    {
        $phone = PhoneNumber::factory()->create();

        MessageLog::factory()->create([
            'phone_number_id' => $phone->id,
            'channel'         => 'whatsapp',
            'status'          => 'delivered',
        ]);

        $filas = $this->hojaDe('/api/export/messages');

        // Encabezado en A1..J1: "Motivo" es la octava columna (indice 7).
        $this->assertSame('Motivo', $filas[0][7]);
        $this->assertEmpty($filas[1][7]);
    }
}
