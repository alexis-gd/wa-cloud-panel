# Limpieza de BD y seeds

Cómo dejar la base ordenada: qué borra cada herramienta y cuál usar según el momento.
Todo se corre por `php artisan` en el VPS (o local). **Nada de esto lo corre `deploy.sh`**
automáticamente (el deploy solo hace `migrate --force`).

## Resumen rápido

| Quiero... | Comando | Borra | Conserva |
|---|---|---|---|
| Limpiar pruebas (chats, SMS, campañas) | `db:clean-demo` | conversaciones, respuestas SMS, campañas, logs, notificaciones, cola | usuarios, números, plantillas, **contactos**, config |
| Limpiar pruebas + contactos | `db:clean-demo --contacts` | lo anterior **+ contactos** (y sus tags) | usuarios, números, plantillas, config |
| Limpiar + borrar contactos y usuarios | `db:clean-demo --contacts --users` | lo anterior **+ contactos + usuarios + tokens** | números, plantillas, config |
| Reponer los usuarios base | `db:seed --class=UserSeeder` | (nada) | crea/actualiza 5 usuarios |
| Reponer los contactos base | `db:seed --class=ContactSeeder` | (nada) | crea/actualiza 4 contactos |
| Reset TOTAL (solo dev) | `migrate:fresh --seed` | **TODO** (drop de todas las tablas) | nada (reseed users + contactos + número) |

## Detalle

### `php artisan db:clean-demo`
Borra solo datos transaccionales de prueba. Pide confirmación (usa `--force` para saltarla).
- Con `--contacts` borra **también** los contactos (y el pivot `contact_tag`).
- Con `--users` borra **también** los usuarios (y sus tokens). Deja el sistema sin login hasta
  que repongas los usuarios con el seed → correr **inmediatamente después** `db:seed --class=UserSeeder`.
- Nunca toca: `phone_numbers`, plantillas (WA/SMS), settings/feature flags.

### `php artisan db:seed --class=UserSeeder`
Crea (o actualiza, idempotente) los 5 usuarios base, dominio **prestamaz.mx**:

| Rol | Email | Password |
|---|---|---|
| superadmin | superadmin@prestamaz.mx | superadmin1234 |
| admin | admin@prestamaz.mx | admin1234 |
| operator | operador@prestamaz.mx | operador1234 |
| agent | agente1@prestamaz.mx | agente1234 |
| agent | agente2@prestamaz.mx | agente1234 |

> No borra usuarios existentes con otros correos. Si en prod quedaron usuarios de
> prueba viejos (ej. `admin@prestamas.mx` sin la Z), bórralos a mano en **Usuarios** o por tinker.

### `php artisan migrate:fresh --seed`
⚠️ **Solo dev.** Hace DROP de todas las tablas y recrea. Reseedea usuarios + contactos de
prueba + número desde `.env`. **NUNCA correr en prod con datos reales.**

## Receta: dejar PROD limpio para el cliente

Borra todo lo de prueba (incluidos contactos y usuarios viejos) y deja el sistema con los
usuarios base del seed. Corre en el VPS, en orden:

```bash
# 1. Deployar el código
./deploy.sh

# 2. Borrar pruebas + contactos + usuarios (viejos), sin pedir confirmación
php artisan db:clean-demo --contacts --users --force

# 3. Reponer los usuarios base limpios (5 usuarios @prestamaz.mx)
php artisan db:seed --class=UserSeeder

# 4a. Si quieres los 4 contactos de prueba de vuelta:
php artisan db:seed --class=ContactSeeder
# 4b. O deja contactos vacío y que el cliente importe su base real en Contactos → Importar
```

> Los pasos 2 y 3 van **juntos**: entre uno y otro el panel queda sin usuarios (nadie puede
> entrar) por unos segundos. El paso 3 repone el superadmin y los demás.
>
> Las **plantillas** no se tocan. Si alguna vez corres `migrate:fresh`, se pierden y hay que
> re-sincronizar WhatsApp desde Meta (botón Sync) y recrear las SMS.
