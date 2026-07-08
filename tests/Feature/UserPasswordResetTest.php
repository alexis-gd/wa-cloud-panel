<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_puede_restablecer_la_contrasena_de_otro_usuario(): void
    {
        $target = User::factory()->create([
            'role'     => 'operator',
            'password' => Hash::make('viejaClave123'),
        ]);

        $response = $this->actingAsAdmin()
                         ->putJson("/api/users/{$target->id}", [
                             'password' => 'nuevaClave456',
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'ok');

        $target->refresh();
        $this->assertTrue(Hash::check('nuevaClave456', $target->password));
        $this->assertFalse(Hash::check('viejaClave123', $target->password));
    }

    public function test_contrasena_menor_a_8_caracteres_se_rechaza(): void
    {
        $target = User::factory()->create(['role' => 'agent']);

        $response = $this->actingAsAdmin()
                         ->putJson("/api/users/{$target->id}", [
                             'password' => 'corta',
                         ]);

        $response->assertStatus(422);
    }

    public function test_operador_no_puede_restablecer_contrasenas(): void
    {
        $target = User::factory()->create(['role' => 'agent']);

        $response = $this->actingAsOperator()
                         ->putJson("/api/users/{$target->id}", [
                             'password' => 'nuevaClave456',
                         ]);

        $response->assertStatus(403);
    }
}
