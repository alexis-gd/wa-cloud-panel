# WA Cloud Panel

Sistema de envío masivo de WhatsApp para empresa de préstamos (México). Caso de uso: **marketing/publicidad** — promocionar préstamos a prospectos. Meta: 200,000 contactos/mes vía WhatsApp Cloud API oficial de Meta.

## Stack técnico

| Capa | Tecnología |
|---|---|
| Backend | Laravel 10 + PHP 8.1 |
| Frontend S1 | Vue 3 vía CDN (sin build) |
| Frontend S2+ | Vue 3 + Vite |
| BD | MySQL 8 |
| Queue S1 | `database` driver |
| Queue S2 | Redis + Laravel Horizon |
| Hosting | VPS Ubuntu 22.04 + Nginx + PHP-FPM |

## Principio de diseño: Sistema a prueba de errores del cliente

El cliente usará el sistema sin supervisión técnica. Cada feature debe asumir que el usuario NO conoce las políticas de Meta. El sistema hace imposible cometer errores que causen suspensión: warm-up automático, horario forzado, opt-out inmediato, solo plantillas aprobadas, tokens nunca expuestos.

## Documentación

| Doc | Contenido |
|---|---|
| [docs/arquitectura-referencia.md](docs/arquitectura-referencia.md) | Estructura Laravel, flujo de peticiones, archivos clave, rutas API Stage 1 |
| [docs/stack-decisiones.md](docs/stack-decisiones.md) | Por qué se eligió cada tecnología y qué se descartó |
| [docs/meta-politicas.md](docs/meta-politicas.md) | Warm-up, precios PMP, calidad de número, plantillas, opt-out, suspensiones |
| [docs/calendario-entregas.md](docs/calendario-entregas.md) | Entregas al cliente + checklist de desarrollo por etapa |
| [docs/testing.md](docs/testing.md) | Guía PHPUnit, tipos de tests, mocks, convenciones |
| [docs/deploy-vps.md](docs/deploy-vps.md) | Receta paso a paso: VPS Ubuntu + Nginx + SSL + Supervisor |
| [docs/guia-operador.md](docs/guia-operador.md) | Manual de usuario para el equipo de Prestamaz |

## Reglas de desarrollo

| Regla | Archivo |
|---|---|
| Estilo PHP/Vue/JS | [.claude/rules/estilo-codigo.md](.claude/rules/estilo-codigo.md) |
| Seguridad inquebrantable | [.claude/rules/seguridad.md](.claude/rules/seguridad.md) |
| Convenciones API REST | [.claude/rules/convenciones-api.md](.claude/rules/convenciones-api.md) |
| **Protección cuenta Meta (PRIORIDAD MÁXIMA)** | [.claude/rules/proteccion-cuenta-meta.md](.claude/rules/proteccion-cuenta-meta.md) |
| Contexto Meta/WhatsApp — decisiones y lecciones | [.claude/rules/contexto-meta-whatsapp.md](.claude/rules/contexto-meta-whatsapp.md) |

## Regla: Mantener la guía de operador actualizada

**Cuándo actualizar [`docs/guia-operador.md`](docs/guia-operador.md):**
- Al agregar un feature nuevo que el operador necesita usar (nueva pantalla, nuevo flujo, nuevo botón visible).
- Al cambiar el comportamiento de algo ya documentado (ej: cambiar el límite de snooze, cambiar el horario, cambiar cómo funciona opt-out).
- Al renombrar o mover algo en la UI que el operador vería (labels, secciones, rutas).

**Cuándo NO es necesario actualizar:**
- Cambios internos de backend sin impacto en la UI del operador.
- Refactors, cambios de performance, ajustes de tests.
- Features solo visibles para admin que el operador no toca.

**Cómo actualizar:** editar la sección correspondiente de `docs/guia-operador.md` en el mismo commit del feature. Si el cambio afecta las FAQ, actualizarlas también.

## ⚠️ OBLIGATORIO antes de cualquier cambio de código

Antes de escribir, editar o borrar cualquier archivo de código (feat, fix, refactor, test, chore), Claude **DEBE** leer y aplicar los lineamientos en [`.claude/commands/lineamientos.md`](.claude/commands/lineamientos.md).

Esto incluye sin excepción:
- Verificar si el cambio afecta la UI del operador → actualizar `docs/guia-operador.md`
- Crear test antes o junto con el código
- Verificar reglas de seguridad si toca envíos o tokens
- Correr `php artisan test` antes del commit

## Comandos

| Comando | Archivo |
|---|---|
| `/test` — correr suite completa | [.claude/commands/test.md](.claude/commands/test.md) |
| `/nueva-feature` — checklist antes de codear | [.claude/commands/nueva-feature.md](.claude/commands/nueva-feature.md) |
| `/lineamientos` — reglas a seguir en CADA cambio | [.claude/commands/lineamientos.md](.claude/commands/lineamientos.md) |

## Credenciales WhatsApp

- **Phone ID**: `1082360764952377`
- **WABA ID**: `1236630511398211`
- **API Version**: `v22.0`
- **Token**: en `.env` → `WA_TOKEN` (referencia), pero los envíos leen de `phone_numbers.token` en BD
- **Token actual**: System User Token **sin expiración** — System User `waclouddev`, app `wa-api-test`, permisos `whatsapp_business_messaging` + `whatsapp_business_management`

## Prompt de retoma

```
Continuamos el desarrollo de wa-cloud-panel. Lee el CLAUDE.md del proyecto para retomar el contexto. Siguiente paso según el checklist en docs/calendario-entregas.md: [describe qué sigue]
```
