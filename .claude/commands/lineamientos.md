# Lineamientos wa-cloud-panel

LEER ANTES de cualquier cambio de código — feat, fix, refactor, test, chore.

---

## 1. Guía de operador — OBLIGATORIO

Antes de hacer el commit, responde: **¿este cambio es visible para el operador en la UI?**

Ejemplos que SÍ requieren actualizar `docs/guia-operador.md`:
- Nueva pantalla, sección, tab o botón visible
- Cambio en el comportamiento de algo ya documentado (límites, flujos, labels)
- Renombrar algo en la UI (columnas, menús, títulos)
- Nuevo flujo de trabajo que el operador debe conocer

Ejemplos que NO requieren actualizar:
- Cambios internos de backend sin impacto en UI
- Refactors, performance, ajustes de tests
- Features solo visibles para admin que el operador no toca

**Cómo verificar**: `grep` el término en `docs/guia-operador.md`. Si el feature existe en la UI pero no en la guía → actualizar antes del commit.

---

## 2. Tests — obligatorio en feat y fix

- Crear test Feature antes o junto con el código, nunca después
- Si el feature toca `WhatsAppClient`, agregar test Unit con mock de la API Meta
- **Nunca modificar tests existentes para que pasen** — si fallan, el código tiene un bug
- Suite completa verde antes del commit: `php artisan test`

---

## 3. Seguridad — verificar siempre (ver `.claude/rules/seguridad.md`)

- Todo HTTP a Meta pasa por `WhatsAppClient::post()` — nunca `Http::` directo
- Tokens en BD con `cast: 'encrypted'` — nunca texto plano
- Nunca loguear token completo — máximo últimos 4 caracteres
- Opt-out verificado antes de cualquier envío
- Rate limiting en todas las rutas API nuevas

---

## 4. API — si el cambio toca endpoints (ver `.claude/rules/convenciones-api.md`)

- Respuesta siempre: `{ "status": "ok", "data": ... }` o `{ "status": "error", "message": "...", "code": "..." }`
- Middleware `ApiKeyMiddleware` en todas las rutas `/api/*`
- Verbos REST estándar, nombres en plural y snake_case
- Códigos HTTP correctos: 200 éxito, 201 creado, 422 datos inválidos, 404 no encontrado

---

## 5. Estilo de código (ver `.claude/rules/estilo-codigo.md`)

- PHP: PSR-12, tipado fuerte en parámetros y retornos, sin `dd()` en código mergeado
- Vue: Composition API (`setup`), CSS en `<style scoped>` con `var(--p-*)` — **Tailwind NO está instalado**
- Sin lógica de negocio en controllers — delegar a Services
- Máximo 200 líneas por archivo

---

## 6. Protección cuenta Meta — si el cambio toca envíos (ver `.claude/rules/proteccion-cuenta-meta.md`)

- ¿El código usa `WhatsAppClient::post()`? Si no → reescribir
- ¿Hay loop de envío sin rate limiting? → agregar delay o queue
- ¿Se verifica opt-out antes de enviar? → si no → agregar check
- ¿Retry sin límite? → máximo 3 intentos con backoff exponencial

---

## 7. Al terminar cualquier cambio

1. `php artisan test` — 100% verde
2. ¿UI visible para operador? → `docs/guia-operador.md` actualizado
3. ¿Feature nuevo en Stage 3? → marcar `[x]` en `docs/calendario-entregas.md`
4. Commit en español, Conventional Commits: `feat:`, `fix:`, `refactor:`, `test:`, `chore:`
5. Sin `Co-Authored-By` en el commit
6. Actualizar versión en `AppLayout.vue` si aplica (ver sección 8)
7. **Actualizar contexto** — según el tipo de cambio, actualizar el doc correspondiente (ver tabla abajo):

| Cambio | Actualizar |
|---|---|
| Nueva regla de seguridad, middleware, rate limit, CORS | `docs/arquitectura-referencia.md` sección Seguridad |
| Nueva variable de entorno requerida | `docs/deploy-vps.md` sección variables `.env` |
| Nuevo endpoint o cambio en un endpoint existente | `docs/arquitectura-referencia.md` sección Rutas API |
| Decisión tecnológica nueva (nueva librería, driver, patrón) | `docs/arquitectura-referencia.md` sección Decisiones |
| Cambio en política Meta (token, warm-up, horario, opt-out) | `.claude/rules/contexto-meta-whatsapp.md` |
| Feature nuevo visible en UI | `docs/guia-operador.md` (ya cubierto en punto 2) |
| Nuevo archivo clave o reestructura de carpetas | `.claude/context-map.md` |

Si el cambio afecta más de un doc, actualizar todos en el mismo commit.

## 8. Versionado — `<span class="version">` en AppLayout.vue

Actualizar la versión en `resources/js/components/AppLayout.vue` cuando:

| Cambio | Incremento | Ejemplo |
|---|---|---|
| `feat:` nuevo feature visible | minor (X.**Y**.0) | v0.3.0 → v0.4.0 |
| `fix:` corrección de bug | patch (X.Y.**Z**) | v0.3.0 → v0.3.1 |
| Nueva Stage completa | minor | v0.3.x → v0.4.0 |

- El label `— Stage N` refleja la etapa activa de desarrollo
- Versión actual: **v0.3.0 — Stage 3**
