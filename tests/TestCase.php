<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Autenticar como admin para tests que requieren auth:sanctum.
     */
    protected function actingAsAdmin(): static
    {
        Sanctum::actingAs(
            User::factory()->create(['role' => 'admin', 'is_active' => true])
        );
        return $this;
    }

    /**
     * Autenticar como superadmin para tests que requieren rutas de configuración.
     */
    protected function actingAsSuperAdmin(): static
    {
        Sanctum::actingAs(
            User::factory()->create(['role' => 'superadmin', 'is_active' => true])
        );
        return $this;
    }

    /**
     * Autenticar como operator para tests que requieren auth:sanctum.
     */
    protected function actingAsOperator(): static
    {
        Sanctum::actingAs(
            User::factory()->create(['role' => 'operator', 'is_active' => true])
        );
        return $this;
    }

    /**
     * Autenticar como agent para tests que requieren auth:sanctum.
     */
    protected function actingAsAgent(): static
    {
        Sanctum::actingAs(
            User::factory()->create(['role' => 'agent', 'is_active' => true])
        );
        return $this;
    }
}
