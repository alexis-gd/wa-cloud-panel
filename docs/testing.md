# Testing — Guía del proyecto

**Herramienta:** PHPUnit (incluido con Laravel). Comando: `php artisan test`

## Tipos de tests

| Tipo | Carpeta | Qué prueba |
|---|---|---|
| Feature | `tests/Feature/` | Endpoints HTTP completos, con BD real en memoria (SQLite) |
| Unit | `tests/Unit/` | Servicios aislados con mocks, sin BD ni API real |

**Regla de oro:** Si agregas un feature y un test existente falla → rompiste algo. El test no se modifica, el código sí.

## Qué testear por etapa

| Etapa | Feature tests | Unit tests |
|---|---|---|
| 1 | health, templates auth, webhook verify, campaigns CRUD, scheduler horario | WhatsAppClient mock, TemplateBuilder JSON, circuit breaker lógica |
| 2 | opt-out STOP/NO/BAJA bloquea envíos, lista negra activa, dashboard stats | WebhookProcessor delivery receipts, métricas agregadas |
| 3 | multi-número balanceo, export CSV válido, inbound almacena | Algoritmo round-robin balanceo |

## Flujo de trabajo obligatorio

```
1. php artisan test          → verde (antes de tocar nada)
2. Escribir test Feature nuevo
3. Implementar el feature
4. php artisan test          → verde (al terminar)
5. Actualizar calendario-entregas.md
6. Commit
```

## Mock de la API de Meta

En tests Unit que tocan `WhatsAppClient`, nunca llamar a Meta real. Usar:

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'graph.facebook.com/*' => Http::response([
        'messages' => [['id' => 'wamid.test123']]
    ], 200),
]);
```

## Configuración de base de datos para tests

En `phpunit.xml` (ya incluido en Laravel):

```xml
<env name="DB_CONNECTION" value="sqlite"/>
<env name="DB_DATABASE" value=":memory:"/>
```

Esto crea una BD en RAM para tests — rápida y aislada, sin tocar MySQL de desarrollo.

## Convenciones de nombres

- `tests/Feature/HealthTest.php` — para el endpoint `/api/health`
- `tests/Feature/CampaignTest.php` — para `/api/campaigns`
- `tests/Unit/WhatsAppClientTest.php` — para el servicio HTTP
- Un archivo por recurso/servicio, no mezclar concerns

## Comandos útiles

```bash
# Correr todos los tests
php artisan test

# Con output detallado
php artisan test --verbose

# Solo un archivo
php artisan test tests/Feature/HealthTest.php

# Con colores
php artisan test --colors
```
