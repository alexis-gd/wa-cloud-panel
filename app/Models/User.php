<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'hidden',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password'          => 'hashed',
        'is_active'         => 'boolean',
        'hidden'            => 'boolean',
    ];

    public function isSuperAdmin(): bool { return $this->role === 'superadmin'; }
    public function isAdmin(): bool      { return in_array($this->role, ['admin', 'superadmin']); }
    public function isOperator(): bool   { return $this->role === 'operator'; }
    public function isAgent(): bool      { return $this->role === 'agent'; }

    /**
     * Jerarquía de la cuenta. Sirve para una sola regla: **nadie administra una cuenta de su
     * mismo nivel o superior**.
     *
     * Antes un `admin` podía cambiarle la contraseña a un `superadmin` desde la pantalla de
     * Usuarios y entrar como él, con acceso al token de Meta. `UserController` solo protegía al
     * usuario de sí mismo.
     *
     * `operator` y `agent` empatan a propósito: ninguno de los dos llega a esta pantalla (la ruta
     * es solo de admin), así que no hay que ordenarlos entre sí.
     */
    public function rango(): int
    {
        return match ($this->role) {
            'superadmin' => 3,
            'admin'      => 2,
            default      => 1,
        };
    }

    /** ¿Este usuario puede administrar (editar, borrar) la cuenta de $otro? */
    public function puedeAdministrarA(self $otro): bool
    {
        return $this->rango() > $otro->rango();
    }
}
