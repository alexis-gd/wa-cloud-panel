<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Dos reglas de la pantalla de Usuarios:
 *
 *   1. Nadie administra una cuenta de su mismo nivel o superior. Antes un `admin` podía cambiarle
 *      la contraseña a un `superadmin` y entrar como él, con acceso al token de Meta.
 *   2. Las cuentas ocultas no se listan ni se administran desde la UI.
 */
class UserRankAndHiddenTest extends TestCase
{
    use RefreshDatabase;

    // ── Regla 1: jerarquía ────────────────────────────────────────────────────

    public function test_un_admin_no_puede_cambiarle_la_contrasena_a_un_superadmin(): void
    {
        $super = User::factory()->create([
            'role'     => 'superadmin',
            'password' => Hash::make('claveDelSuper123'),
        ]);

        $this->actingAsAdmin()
             ->putJson("/api/users/{$super->id}", ['password' => 'meLaRobo456'])
             ->assertStatus(403)
             ->assertJsonPath('code', 'INSUFFICIENT_RANK');

        $this->assertTrue(Hash::check('claveDelSuper123', $super->refresh()->password));
    }

    public function test_un_admin_no_puede_borrar_a_un_superadmin(): void
    {
        $super = User::factory()->create(['role' => 'superadmin']);

        $this->actingAsAdmin()
             ->deleteJson("/api/users/{$super->id}")
             ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $super->id]);
    }

    public function test_un_admin_no_puede_administrar_a_otro_admin(): void
    {
        $otro = User::factory()->create(['role' => 'admin']);

        $this->actingAsAdmin()
             ->putJson("/api/users/{$otro->id}", ['is_active' => false])
             ->assertStatus(403);

        $this->assertTrue($otro->refresh()->is_active);
    }

    public function test_un_admin_si_puede_administrar_a_un_operador(): void
    {
        $operador = User::factory()->create(['role' => 'operator', 'is_active' => true]);

        $this->actingAsAdmin()
             ->putJson("/api/users/{$operador->id}", ['is_active' => false])
             ->assertStatus(200);

        $this->assertFalse($operador->refresh()->is_active);
    }

    public function test_un_superadmin_si_puede_administrar_a_un_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);

        $this->actingAsSuperAdmin()
             ->putJson("/api/users/{$admin->id}", ['is_active' => false])
             ->assertStatus(200);

        $this->assertFalse($admin->refresh()->is_active);
    }

    public function test_un_superadmin_no_puede_administrar_a_otro_superadmin(): void
    {
        $otro = User::factory()->create(['role' => 'superadmin']);

        $this->actingAsSuperAdmin()
             ->putJson("/api/users/{$otro->id}", ['password' => 'loQueSea789'])
             ->assertStatus(403);
    }

    // ── Regla 2: cuentas ocultas ──────────────────────────────────────────────

    public function test_un_usuario_oculto_no_aparece_en_la_lista(): void
    {
        $oculto  = User::factory()->create(['role' => 'superadmin', 'hidden' => true]);
        $visible = User::factory()->create(['role' => 'operator']);

        $ids = collect($this->actingAsSuperAdmin()->getJson('/api/users')->assertOk()->json('data'))
            ->pluck('id');

        $this->assertFalse($ids->contains($oculto->id));
        $this->assertTrue($ids->contains($visible->id));
    }

    /** 404 y no 403: un 403 confirmaría que ese id existe. */
    public function test_un_usuario_oculto_no_se_puede_administrar_ni_por_id(): void
    {
        $oculto = User::factory()->create(['role' => 'operator', 'hidden' => true]);

        $this->actingAsSuperAdmin()
             ->putJson("/api/users/{$oculto->id}", ['is_active' => false])
             ->assertStatus(404);

        $this->assertTrue($oculto->refresh()->is_active);
    }

    public function test_un_usuario_oculto_tampoco_se_puede_borrar(): void
    {
        $oculto = User::factory()->create(['role' => 'operator', 'hidden' => true]);

        $this->actingAsSuperAdmin()
             ->deleteJson("/api/users/{$oculto->id}")
             ->assertStatus(404);

        $this->assertDatabaseHas('users', ['id' => $oculto->id]);
    }

    /** Ocultar no es un permiso: la cuenta entra al panel como cualquier otra. */
    public function test_un_usuario_oculto_si_puede_entrar_al_panel(): void
    {
        User::factory()->create([
            'email'     => 'alexis.oculto@prestamaz.mx',
            'password'  => Hash::make('claveSegura123'),
            'role'      => 'superadmin',
            'hidden'    => true,
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'email'    => 'alexis.oculto@prestamaz.mx',
            'password' => 'claveSegura123',
        ])->assertStatus(200);
    }

    // ── El comando ────────────────────────────────────────────────────────────

    public function test_el_comando_crea_la_cuenta_oculta(): void
    {
        $this->artisan('usuarios:superadmin-oculto', ['email' => 'alexis@prestamaz.mx'])
             ->expectsQuestion('Contraseña (mínimo 8 caracteres, no se ve al escribir)', 'claveSegura123')
             ->expectsQuestion('Repite la contraseña', 'claveSegura123')
             ->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email'  => 'alexis@prestamaz.mx',
            'role'   => 'superadmin',
            'hidden' => true,
        ]);
    }

    public function test_el_comando_rechaza_contrasenas_que_no_coinciden(): void
    {
        $this->artisan('usuarios:superadmin-oculto', ['email' => 'alexis@prestamaz.mx'])
             ->expectsQuestion('Contraseña (mínimo 8 caracteres, no se ve al escribir)', 'claveSegura123')
             ->expectsQuestion('Repite la contraseña', 'otraCosa456')
             ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'alexis@prestamaz.mx']);
    }
}
