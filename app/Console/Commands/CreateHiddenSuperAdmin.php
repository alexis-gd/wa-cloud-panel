<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;

/**
 * Crea (o actualiza) una cuenta superadmin que NO se lista en la pantalla de Usuarios.
 *
 * Existe por auditoría: mientras varias personas compartan una misma cuenta superadmin, el
 * registro de quién hizo qué no vale nada. Una cuenta por persona arregla eso, y ocultarla evita
 * que el cliente vea o administre cuentas técnicas.
 *
 * La contraseña se pide en pantalla, nunca por argumento: un argumento queda escrito en el
 * historial del shell (`~/.bash_history`) y en la lista de procesos del servidor.
 */
class CreateHiddenSuperAdmin extends Command
{
    protected $signature = 'usuarios:superadmin-oculto
                            {email : Correo de la cuenta}
                            {--nombre= : Nombre visible (por defecto, la parte antes de la @)}
                            {--visible : Crearla SIN ocultar, como un superadmin normal}';

    protected $description = 'Crea un superadmin que no aparece en la pantalla de Usuarios';

    public function handle(): int
    {
        $email  = trim((string) $this->argument('email'));
        $nombre = trim((string) ($this->option('nombre') ?: explode('@', $email)[0]));
        $oculto = ! $this->option('visible');

        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            $this->error("'{$email}' no es un correo válido.");
            return self::FAILURE;
        }

        $password = $this->secret('Contraseña (mínimo 8 caracteres, no se ve al escribir)');

        if ($password !== $this->secret('Repite la contraseña')) {
            $this->error('Las contraseñas no coinciden.');
            return self::FAILURE;
        }

        $reglas = Validator::make(['password' => $password], ['password' => ['required', Password::min(8)]]);

        if ($reglas->fails()) {
            $this->error($reglas->errors()->first('password'));
            return self::FAILURE;
        }

        $existente = User::where('email', $email)->first();

        // Idempotente a propósito: correrlo dos veces cambia la contraseña en vez de reventar por
        // el unique del correo. Sirve como "olvidé mi contraseña" de una cuenta oculta, que por
        // definición no se puede arreglar desde el panel.
        if ($existente) {
            if (! $this->confirm("Ya existe '{$email}'. ¿Actualizar su contraseña y dejarla como superadmin"
                . ($oculto ? ' oculto' : '') . '?')) {
                $this->line('Sin cambios.');
                return self::SUCCESS;
            }

            $existente->update([
                'password'  => Hash::make($password),
                'role'      => 'superadmin',
                'is_active' => true,
                'hidden'    => $oculto,
            ]);

            // Cerrar sesiones abiertas: si se cambió la contraseña por sospecha, dejar vivos los
            // tokens viejos haría inútil el cambio.
            $existente->tokens()->delete();

            $this->info("Cuenta actualizada: {$email}" . ($oculto ? ' (oculta)' : ''));
            $this->line('Se cerraron sus sesiones abiertas.');

            return self::SUCCESS;
        }

        $user = User::create([
            'name'      => $nombre,
            'email'     => $email,
            'password'  => Hash::make($password),
            'role'      => 'superadmin',
            'is_active' => true,
            'hidden'    => $oculto,
        ]);

        $this->info("Superadmin creado: {$user->email} (id {$user->id})" . ($oculto ? ' - oculto' : ''));
        $this->line('Entra al panel con ese correo. No va a aparecer en la pantalla de Usuarios.');

        return self::SUCCESS;
    }
}
