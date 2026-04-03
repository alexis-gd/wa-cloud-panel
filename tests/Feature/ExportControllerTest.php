<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── GET /api/export/contacts ──────────────────────────────────────────────

    public function test_export_contacts_admin_retorna_200_con_content_type_xlsx(): void
    {
        Contact::factory()->count(3)->create();

        $response = $this->actingAsAdmin()
                         ->get('/api/export/contacts');

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
    }

    public function test_export_contacts_operator_retorna_200_con_content_type_xlsx(): void
    {
        Contact::factory()->count(2)->create();

        $response = $this->actingAsOperator()
                         ->get('/api/export/contacts');

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
    }

    public function test_export_contacts_agent_retorna_403(): void
    {
        $this->actingAsAgent()
             ->get('/api/export/contacts')
             ->assertStatus(403);
    }

    public function test_export_contacts_sin_auth_retorna_401(): void
    {
        $this->getJson('/api/export/contacts')
             ->assertStatus(401);
    }

    public function test_export_contacts_retorna_archivo_no_vacio(): void
    {
        Contact::factory()->count(5)->create();

        $response = $this->actingAsAdmin()
                         ->get('/api/export/contacts');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->streamedContent());
    }

    // ── GET /api/export/messages ──────────────────────────────────────────────

    public function test_export_messages_admin_retorna_200_con_content_type_xlsx(): void
    {
        $phone = PhoneNumber::factory()->create();
        MessageLog::factory()->count(3)->create(['phone_number_id' => $phone->id]);

        $response = $this->actingAsAdmin()
                         ->get('/api/export/messages');

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
    }

    public function test_export_messages_operator_retorna_200_con_content_type_xlsx(): void
    {
        $response = $this->actingAsOperator()
                         ->get('/api/export/messages');

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
    }

    public function test_export_messages_agent_retorna_403(): void
    {
        $this->actingAsAgent()
             ->get('/api/export/messages')
             ->assertStatus(403);
    }

    public function test_export_messages_sin_datos_retorna_200(): void
    {
        $response = $this->actingAsAdmin()
                         ->get('/api/export/messages');

        $response->assertStatus(200);
    }
}
