# Lineamientos wa-cloud-panel

LEER ANTES de cualquier cambio de código — feat, fix, refactor, test, chore.

> **Regla de contexto: no duplicar.** Si una regla ya existe en `~/.claude/CLAUDE.md` (global) o en otro archivo del proyecto, referenciarla — no copiarla. La fuente de verdad es un solo lugar. Si algo cambia, cambia en un lugar y se propaga.

---

## 0. Verificación de rama — ANTES de cualquier cambio

Ejecutar siempre al inicio de una tarea nueva:

```bash
git fetch origin
git status
git branch -vv
```

### Decidir si continuar en la rama actual o crear una nueva

**Continuar en la rama actual si:**
- Estamos en una rama activa (`feature/*`, `fix/*`, `chore/*`, etc.) **y** el cambio es del mismo scope **y** la rama aún NO ha sido mergeada a `develop`

**Crear rama nueva si:**
- Estamos en `main` o `develop`
- La rama actual ya fue mergeada — aunque el scope sea el mismo, el ciclo de esa rama terminó. Mezclar historial viejo con cambios nuevos ensucia el PR
- La rama es de un scope claramente distinto al cambio solicitado

### Acciones según estado detectado

| Situación | Acción |
|---|---|
| En `main` o `develop` | `git checkout develop && git pull && git checkout -b tipo/nombre` |
| En rama mergeada, sin cambios | `git checkout develop && git pull && git checkout -b tipo/nombre` |
| En rama activa del mismo scope, limpia y al día | Continuar en esa rama |
| Rama local desactualizada vs `origin/develop` | `git pull origin develop --rebase` antes de continuar |
| Cambios sin commitear de scope diferente | Avisar al usuario — stash o commit antes de cambiar |

### Reporte obligatorio al usuario antes de empezar

```
📍 Rama actual: nombre-de-rama
📊 Estado: limpia / N cambios pendientes
🔄 Remoto: al día / N commits atrás / N commits adelante
🌿 Decisión: continuar aquí / crear rama nueva `tipo/nombre`
```

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
3. ¿Feature nuevo en Stage 3? → **preguntar al usuario antes de marcar `[x]` en `docs/calendario-entregas.md`** — no marcar como listo sin confirmación explícita
4. **¿El commit es `feat:` o `fix:`? → OBLIGATORIO actualizar versión en `AppLayout.vue` antes de commitear** (ver sección 9)
5. Ejecutar flujo de commit — **ver sección 8** (preview → aprobación → commit)
6. Sin `Co-Authored-By` en el commit
7. **Actualizar contexto** — consultar la tabla en [`.claude/context-map.md`](.claude/context-map.md) sección "Regla práctica" y actualizar los docs que correspondan. Si el cambio afecta más de uno, actualizar todos en el mismo commit.

---

## 8. Flujo de commit (obligatorio — nunca saltarse)

### Ramas
- **Nunca commitear directo a `main` ni a `develop`** — siempre en rama propia
- Toda rama nace desde `develop` y muere al hacer merge a `develop`
- Merge a `main` solo cuando el usuario valide los cambios en `develop`
- Hotfix urgente (bug en producción): rama `hotfix/kebab-name` desde `main`, merge a `main` + `develop`

| Tipo | Prefijo | Ejemplo |
|---|---|---|
| Feature nuevo | `feature/` | `feature/sms-campaign` |
| Bug fix | `fix/` | `fix/login-redirect` |
| Mantenimiento/config | `chore/` | `chore/branch-strategy` |
| Documentación | `docs/` | `docs/operador-guide` |
| Refactor | `refactor/` | `refactor/auth-service` |

### Proceso
- [ ] Crear rama desde `develop`: `git checkout develop && git pull && git checkout -b tipo/kebab-name`
- [ ] Mostrar preview al usuario ANTES de commitear:
  - Rama: `tipo/kebab-name`
  - Mensaje propuesto: seguir convención global (`~/.claude/CLAUDE.md` → Git commits)
  - Archivos que se van a incluir
- [ ] Esperar aprobación explícita
- [ ] Solo entonces ejecutar `git add` + `git commit`
- [ ] `git push origin tipo/kebab-name`
- [ ] Abrir PR en GitHub: rama → `develop`. **Siempre PR, sin merge local**
- [ ] Merge a `main` igual: PR desde `develop` → `main` en GitHub, solo cuando el usuario valide

---

## 9. Versionado — `<span class="version">` en AppLayout.vue

Archivo: `resources/js/components/AppLayout.vue` — buscar `<span class="version">`.

| Tipo de commit | Incremento | Ejemplo |
|---|---|---|
| `feat:` | minor (X.**Y**.0) — resetea patch a 0 | v0.5.2 → v0.6.0 |
| `fix:` o `perf:` | patch (X.Y.**Z**) | v0.5.2 → v0.5.3 |
| Nueva Stage completa | minor | v0.5.x → v0.6.0 |

- `test:`, `chore:`, `docs:`, `refactor:` **NO** incrementan versión
- Siempre incluir `AppLayout.vue` en el mismo commit que el `feat:` o `fix:`, nunca en un commit separado
- Versión actual: **v0.5.2 — Stage 3**
