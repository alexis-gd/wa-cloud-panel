# WA Cloud Panel

Sistema de envío masivo multicanal (WhatsApp + SMS) para empresa de préstamos (México). Caso de uso: **marketing/publicidad** — promocionar préstamos a prospectos. Meta: 200,000 contactos/mes vía WhatsApp Cloud API oficial de Meta + SMS vía Twilio.

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
| WhatsApp | Meta Cloud API directa (sin intermediarios) |
| RAM 8GB(alternativa: SMS Masivos si requieren CFDI) |

## Principio de diseño: Sistema a prueba de errores del cliente

El cliente usará el sistema sin supervisión técnica. Cada feature debe asumir que el usuario NO conoce las políticas de Meta ni las regulaciones de SMS. El sistema hace imposible cometer errores que causen suspensión: warm-up automático, horario forzado (WA), opt-out inmediato (ambos canales), solo plantillas aprobadas (WA), tokens nunca expuestos, cooldown anti-duplicado cross-channel.

## Documentación

> Ver [.claude/context-map.md](.claude/context-map.md) para saber qué archivo actualizar según el tipo de cambio.

| Doc | Contenido |
|---|---|
| [docs/arquitectura-referencia.md](docs/arquitectura-referencia.md) | Estructura Laravel, flujo de peticiones, archivos clave, decisiones tecnológicas |
| [docs/sms-referencia.md](docs/sms-referencia.md) | Arquitectura multicanal SMS, flujo de campaña, anti-duplicado, delivery reports |
| [docs/guia-sms-gateway-setup.md](docs/guia-sms-gateway-setup.md) | ✅ **EN USO** — montar el gateway capcom6 (Docker + Cloudflare Tunnel + teléfono) y conectarlo al panel |
| [docs/sms-sim-propia-analisis.md](docs/sms-sim-propia-analisis.md) | Análisis SMS por SIM propia (Android gateway) vs proveedor: legal, económico, riesgos |
| [docs/guia-twilio-setup.md](docs/guia-twilio-setup.md) | ⚠️ Referencia (NO elegido): setup Twilio. Evaluado pero se optó por SIM propia |
| [docs/calendario-entregas.md](docs/calendario-entregas.md) | Entregas al cliente + checklist de desarrollo por etapa + backlog técnico |
| [docs/testing.md](docs/testing.md) | Guía PHPUnit, tipos de tests, mocks, convenciones |
| [docs/deploy-vps.md](docs/deploy-vps.md) | Receta paso a paso: VPS Ubuntu + Nginx + SSL + Supervisor |
| [docs/guia-operador.md](docs/guia-operador.md) | Manual de usuario para el equipo de Prestamaz |
| [docs/qa-manual.md](docs/qa-manual.md) | Checklist de QA manual por módulo (happy path + casos borde) |
| [docs/plan-realtime.md](docs/plan-realtime.md) | Tiempo real (Soketi): estado, pollings a matar, hoja de ruta de sockets (campañas, campanita, dashboard, SMS) |
| [docs/guia-realtime-soketi.md](docs/guia-realtime-soketi.md) | Montaje de Soketi (Docker + Supervisor/Nginx wss) + variables + cómo verificar |

## Reglas de desarrollo

| Regla | Archivo |
|---|---|
| Estilo PHP/Vue/JS | [.claude/rules/estilo-codigo.md](.claude/rules/estilo-codigo.md) |
| Seguridad inquebrantable | [.claude/rules/seguridad.md](.claude/rules/seguridad.md) |
| Convenciones API REST | [.claude/rules/convenciones-api.md](.claude/rules/convenciones-api.md) |
| **Protección cuenta Meta (PRIORIDAD MÁXIMA)** | [.claude/rules/proteccion-cuenta-meta.md](.claude/rules/proteccion-cuenta-meta.md) |
| Contexto Meta/WhatsApp — decisiones, políticas y lecciones | [.claude/rules/contexto-meta-whatsapp.md](.claude/rules/contexto-meta-whatsapp.md) |
| Contexto SMS — proveedor elegido (SIM propia/capcom6), reglas, cooldown, legal | [.claude/rules/contexto-sms.md](.claude/rules/contexto-sms.md) |

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

## Credenciales SMS (SIM propia — gateway capcom6)

- **Proveedor elegido**: SIM propia con **SMS Gateway for Android** (capcom6) self-host. Twilio se evaluó pero **no** se eligió.
- **Credenciales**: en `.env` → `SMS_GATEWAY_URL`, `SMS_GATEWAY_LOGIN`, `SMS_GATEWAY_PASSWORD`, `SMS_WEBHOOK_SECRET`. Los envíos pasan por `SmsGatewayClient::send()` (`POST {url}/messages`, Basic auth).
- **Setup**: ver [docs/guia-sms-gateway-setup.md](docs/guia-sms-gateway-setup.md) (Docker + Cloudflare Tunnel + teléfono). En prod desde 2026-07-02: `gw.prestamaz.site`, 1 teléfono (SIM Telcel).
- **Twilio (referencia, no usado)**: [docs/guia-twilio-setup.md](docs/guia-twilio-setup.md).

## Estrategia de ramas

| Rama | Propósito |
|---|---|
| `main` | Producción validada — nunca commitear directo |
| `develop` | Integración — toda la actividad de desarrollo va aquí |
| `feature/*` | Features nuevos — nacen y mueren en `develop` |
| `fix/*` | Bugs — nacen y mueren en `develop` |
| `hotfix/*` | Urgencias en prod — desde `main`, merge a `main` + `develop` |

Rama activa de trabajo: **`develop`**. Merge a `main` solo cuando el usuario valide.

## Prompt de retoma

```
Continuamos el desarrollo de wa-cloud-panel. Lee el CLAUDE.md del proyecto para retomar el contexto. Siguiente paso según el checklist en docs/calendario-entregas.md: [describe qué sigue]
```
