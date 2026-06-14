# Referencia de arquitectura — Prestamaz Panel

## Estructura de carpetas Laravel

```
app/
  Models/          ← Clases que representan tablas de la BD (1 clase = 1 tabla)
  Services/        ← Lógica de negocio (nosotros la creamos, no es de Laravel)
  Http/
    Controllers/   ← Reciben peticiones HTTP y devuelven respuestas JSON o HTML
    Middleware/    ← Filtros que se ejecutan ANTES de llegar al controller
config/            ← Archivos de configuración (BD, servicios, caché, etc.)
database/
  migrations/      ← Definen la estructura de las tablas (como scripts SQL en PHP)
  seeders/         ← Insertan datos iniciales en la BD
routes/
  api.php          ← Rutas que empiezan con /api/...
  web.php          ← Rutas normales del navegador
resources/views/   ← HTML (templates Blade, extensión .blade.php)
public/            ← Lo único accesible desde el navegador directamente
```

## Cómo fluye una petición API

```
Navegador/cliente
    → routes/api.php          (¿qué ruta coincide?)
    → Middleware               (¿tiene X-API-Key válida?)
    → Controller               (ejecuta la lógica)
    → Service / Model          (habla con Meta o con la BD)
    → respuesta JSON
```

## Archivos del proyecto y para qué sirven

| Archivo | Qué hace |
|---|---|
| `Models/PhoneNumber.php` | Tabla `phone_numbers`. El cast `encrypted` encripta el token automáticamente con AES-256 |
| `Models/WaTemplate.php` | Tabla `wa_templates`. Plantillas aprobadas por Meta |
| `Models/MessageLog.php` | Tabla `message_log`. Cada mensaje enviado. Métodos: `logSend()`, `updateFromResponse()`, `updateStatus()` |
| `Services/WhatsApp/WhatsAppClient.php` | **Único** lugar que hace HTTP a Meta. Todos los envíos pasan por aquí (regla del proyecto) |
| `Services/WhatsApp/TemplateBuilder.php` | Arma el JSON que Meta espera para enviar una plantilla aprobada |
| `Middleware/ApiKeyMiddleware.php` | Verifica header `X-API-Key` en cada petición a `/api/*` |
| `Controllers/Api/TemplateController.php` | `GET /api/templates` y `POST /api/templates/send-test` |
| `Controllers/Api/WebhookController.php` | `GET /webhook` (verificación Meta) y `POST /webhook` (eventos de entrega/lectura) |
| `Controllers/Api/DashboardController.php` | `GET /api/dashboard/stats` — últimos 20 mensajes y totales |
| `migrations/` | Crean las 3 tablas en MySQL al correr `php artisan migrate` |
| `seeders/PhoneNumberSeeder.php` | Insertó la fila inicial con el número de prueba de Meta |
| `resources/views/app.blade.php` | HTML del panel. Usa Vue 3 vía CDN |
| `public/assets/js/app.js` | JavaScript Vue que consume la API del panel |

## Rutas API (Stage 1)

| Método | Ruta | Auth | Qué hace |
|---|---|---|---|
| GET | `/api/health` | No | Verifica que el servidor y la BD respondan |
| GET | `/api/templates` | X-API-Key | Lista plantillas activas en BD |
| POST | `/api/templates/send-test` | X-API-Key | Envía un mensaje de prueba a un número |
| GET | `/api/dashboard/stats` | X-API-Key | Últimos 20 mensajes + totales por estado |
| GET | `/webhook` | No (token Meta) | Verificación inicial de Meta |
| POST | `/webhook` | Firma HMAC | Eventos de entrega/lectura de Meta |

## Estados de un mensaje (message_log.status)

```
pending → sent → delivered → read
                ↘ failed
```

## Librerías externas

### Stage 1 — todo nativo de Laravel 10, ZERO librerías extra
| Lo que usamos | Viene de |
|---|---|
| `Http::withToken()->post()` | Laravel (wrapper de Guzzle) |
| `cast: 'encrypted'` en modelos | Laravel Eloquent |
| `response()->json()` | Laravel Routing |
| `Log::error()` | Laravel Log (escribe en `storage/logs/`) |
| `php artisan migrate` | Laravel Database |
| Vue 3 | CDN (sin instalación) |

### Stage 2 — librerías que agregaremos
| Librería | Para qué |
|---|---|
| `phpoffice/phpspreadsheet` | Leer archivos Excel con los 200k contactos |
| `predis/predis` | Cliente PHP para conectar Redis |

## Vue 3: CDN vs instalado

### CDN (Stage 1 — actual)
- Se carga desde internet con un `<script src="...">`
- Sin `npm install`, sin archivos de build, sin Vite
- Simple y rápido de arrancar
- Limitaciones: no hay componentes separados en archivos `.vue`, no hay TypeScript, carga desde internet

### Instalado con Vite (Stage 2+)
- `npm install` + `npm run build` genera archivos optimizados
- Componentes en archivos `.vue` separados (mejor organización)
- Tree shaking: solo incluye el código que usas (archivo más pequeño)
- Hot reload en desarrollo
- Correcto para producción

## Redis vs Queue database

### Queue driver: database (Stage 1 — actual)
- Los trabajos pendientes se guardan en una tabla MySQL (`jobs`)
- Sin instalar nada extra
- Más lento (consultas SQL para cada trabajo)
- Suficiente para desarrollo y volúmenes bajos

### Queue driver: redis (Stage 2)
- Los trabajos se guardan en RAM (Redis)
- Mucho más rápido — necesario para 200k mensajes/mes
- Requiere instalar Redis en el VPS y `predis/predis` en PHP
- Se gestiona con Laravel Horizon (panel de monitoreo de colas)

## Seguridad implementada

1. `WA_WEBHOOK_VERIFY_TOKEN` — secreto SEPARADO del bearer token de Meta
2. Validación de `X-Hub-Signature-256` en cada webhook POST (HMAC-SHA256)
3. Token de WhatsApp encriptado en BD con `cast: 'encrypted'` (AES-256 via APP_KEY)
4. Endpoints protegidos con `X-API-Key` header (ApiKeyMiddleware)
5. Rate limiting: `throttle:60,1` en rutas autenticadas; `throttle:5,1` en `POST /auth/login` (anti-brute-force)
6. `.env` en `.gitignore` — nunca se sube al repositorio
7. CORS restringido a `APP_URL` + Vite dev (no `*`); métodos explícitos; `max_age: 7200`
8. Tokens Sanctum expiran a las **8 horas**; `sanctum:prune-expired` corre diario vía scheduler

## Decisiones tecnológicas — por qué se eligió cada cosa

| Capa | Elegido | Descartados | Razón |
|---|---|---|---|
| Backend | Laravel 10 + PHP 8.1 | Symfony (overkill), CodeIgniter (sin queues), Node (no lo domina el dev) | Queue, scheduler, cast `encrypted`, middleware y rate limiting built-in |
| Frontend S1 | Vue 3 CDN | React (build obligatorio), Blade puro (sin reactividad) | Sin npm, 3 tabs no justifican build step |
| Frontend S2+ | Vue 3 + Vite + PrimeVue v4 | Vuetify (Material genérico), Naive UI, shadcn-vue | Componentes ricos, tema Aura moderno, ideal para dashboard empresarial |
| BD | MySQL 8 | PostgreSQL (innecesario), SQLite (no escala) | Dev lo domina, soporte Laravel primera clase, suficiente para 200K contactos |
| Tests BD | MySQL (`wa_cloud_panel_test`) | SQLite :memory: | Mismo motor dev=test=prod — XAMPP ya corre MySQL |
| Queue S1 | `database` driver | Redis (instalación extra) | Zero setup, suficiente para desarrollo |
| Queue S2 | Redis + Horizon | — | RAM-based para 200K/mes, Horizon para monitoreo visual |
| Hosting | VPS Ubuntu 22.04 + Nginx | cPanel | Obligatorio para `queue:work` persistente y Redis |
| VPS proveedor | Hetzner CX22 ~$5/mes | DigitalOcean ($6), Vultr ($5) | Mejor relación precio/specs |
| API WhatsApp | Cloud API directa | Twilio ($$$), MessageBird ($$) | Sin intermediarios, control total, PMP directo sin markup |

### Por qué NO cPanel

cPanel no permite:
- Procesos persistentes (`queue:work` + Supervisor)
- Redis sin addon pago
- Configuración de Nginx (solo Apache)
- Cron con frecuencia menor a 1 minuto

Para queues de WhatsApp masivas esto es bloqueante. VPS propio es el único camino viable.

### Por qué Cloud API directa (no Twilio ni MessageBird)

- Twilio cobra markup encima del PMP de Meta (~2–3× más caro)
- Con Cloud API directa: `~$0.04/msg Marketing` × 200K = $8,000 USD/mes sin margen de intermediario
- Control total sobre webhooks, delivery receipts y plantillas
- Sin vendor lock-in

### Por qué `unreachable` como status separado (no `invalid`)

`invalid` significa que Meta rechazó el número (`error 131026` — no tiene WhatsApp).
`unreachable` significa que tiene WhatsApp pero los mensajes nunca llegan (probable bloqueo).
Son causas distintas con comportamientos distintos: `invalid` es permanente, `unreachable`
es reversible si el contacto vuelve a escribir.

**Regla de detección:** 2+ mensajes en `sent` con el más antiguo de 30+ días y ningún
`delivered`/`read` histórico. Los 30 días corresponden al TTL de expiración de mensajes
de WhatsApp — un mensaje de 30+ días en `sent` jamás se entregará.

**Horario del comando `wa:mark-unreachable`:** 6:00 AM CST — antes de que abra la ventana
de envíos (9AM). Sin contención de BD con los jobs de campaña.

Ver plan de implementación completo en [`docs/plan-unreachable.md`](plan-unreachable.md).

---

## Comandos artisan usados (referencia)

```bash
# Correr migraciones (crear tablas en BD)
php artisan migrate

# Correr un seeder específico (insertar datos iniciales)
php artisan db:seed --class=PhoneNumberSeeder

# Ver rutas registradas
php artisan route:list

# Limpiar caché de configuración (necesario si cambias .env)
php artisan config:clear

# Consola interactiva PHP con acceso a Laravel
php artisan tinker
```
