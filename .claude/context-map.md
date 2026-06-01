# context-map — wa-cloud-panel
> Este archivo explica para qué sirve cada contexto del proyecto, cómo se relacionan y cuándo actualizar cada uno.
> Leer este mapa antes de actualizar cualquier MD o antes de crear nuevas reglas de documentación.

---

## Objetivo

El proyecto usa varios archivos de contexto porque cada uno tiene una función distinta.
La idea es evitar mezclar:
- reglas permanentes de trabajo
- estado actual del producto y pendientes
- conocimiento acumulado de Meta / WhatsApp / Twilio SMS
- memoria operativa y pitfalls de sesión

Si todo se mezcla, el contexto se vuelve pesado, se repite y es difícil de mantener.

---

## Mapa de archivos

```
CLAUDE.md                              ← punto de entrada, referencias a todo lo demás
│
├── docs/                              ← documentación del proyecto (vive en el repo)
│   ├── calendario-entregas.md         ← qué existe, qué falta, backlog técnico
│   ├── guia-operador.md               ← manual de usuario para el equipo Prestamaz
│   ├── qa-manual.md                   ← checklist de pruebas manuales por módulo
│   ├── arquitectura-referencia.md     ← cómo está construido + decisiones tecnológicas
│   ├── sms-referencia.md              ← arquitectura multicanal SMS, flujos, anti-duplicado
│   ├── testing.md                     ← guía PHPUnit, convenciones de tests
│   └── deploy-vps.md                  ← receta de deploy VPS Ubuntu + Nginx + SSL
│
├── .claude/                           ← instrucciones y reglas para Claude (viven en el repo)
│   ├── context-map.md                 ← este archivo
│   ├── rules/
│   │   ├── seguridad.md               ← reglas de seguridad inquebrantables
│   │   ├── estilo-codigo.md           ← convenciones PHP, Vue, CSS
│   │   ├── convenciones-api.md        ← formato de respuestas, rutas, middlewares
│   │   ├── proteccion-cuenta-meta.md  ← checklist protección cuenta Meta (PRIORIDAD MÁXIMA)
│   │   ├── contexto-meta-whatsapp.md  ← conocimiento acumulado de Meta/WhatsApp API
│   │   └── contexto-twilio-sms.md     ← conocimiento acumulado de Twilio/SMS: errores, opt-out, legal, cooldown
│   └── commands/
│       ├── lineamientos.md            ← checklist obligatorio ANTES de cada cambio de código
│       ├── nueva-feature.md           ← checklist al arrancar un feature nuevo
│       └── test.md                    ← comando /test — cómo correr la suite
│
└── MEMORY.md  (fuera del repo)        ← pitfalls y lecciones entre sesiones de Claude
```

---

## Fuente de verdad por archivo

### `CLAUDE.md`
**Para qué sirve:**
- Punto de entrada para Claude — referencia a dónde está cada cosa
- Stack técnico y decisiones arquitectónicas permanentes
- Lista de reglas y docs que Claude debe leer

**Sí va aquí:**
- Referencia al stack (Laravel 10, Vue 3, MySQL, etc.)
- Credenciales WhatsApp y SMS (Phone ID, WABA ID, Twilio SID — no tokens)
- Prompt de retoma de sesión
- Links a los demás docs

**No va aquí:**
- Estado actual del proyecto (features implementadas, pendientes)
- Pitfalls técnicos o bugs resueltos
- Listas largas de cualquier tipo
- Contenido que cambia semana a semana

**Actualizar cuando:**
- Cambie una decisión arquitectónica permanente
- Cambie el stack o una convención global
- Se agregue un nuevo documento de reglas

---

### `docs/calendario-entregas.md`
**Para qué sirve:** estado funcional actual del producto — qué existe, qué falta, qué está en progreso

**Sí va aquí:**
- Checklist `[x]` / `[ ]` de features por Stage/Etapa
- Pendientes técnicos de backlog (optimizaciones, redis, deploy, etc.)
- Notas de por qué algo está pendiente
- Estimados de entrega al cliente

**No va aquí:**
- Decisiones técnicas de implementación
- Pitfalls o bugs resueltos
- Schema de BD o detalles de API Meta/Twilio
- QA y casos de prueba (eso va en `docs/qa-manual.md`)

**Actualizar cuando:**
- Se complete un feature → marcar `[x]`
- Se descubra un pendiente nuevo → agregar `[ ]`
- Cambie el plan de entrega al cliente

---

### `docs/sms-referencia.md`
**Para qué sirve:** documento técnico de referencia para el canal SMS — arquitectura multicanal, flujos, comparativo con WhatsApp, anti-duplicado, delivery reports

**Sí va aquí:**
- Diagrama de arquitectura multicanal (WA + SMS)
- Comparativo de canales (horarios, opt-out, costos, riesgos)
- Flujo de creación de campaña multicanal
- Protección anti-duplicado cross-channel
- Reglas de SmsClient.php
- Mapeo de estados Twilio a acciones en BD
- Cumplimiento legal SMS México

**No va aquí:**
- Estado de features (va en calendario)
- Reglas de código generales (va en rules/)
- Pitfalls puntuales (va en MEMORY.md)

**Actualizar cuando:**
- Cambie el flujo multicanal o se agregue un canal nuevo
- Cambie la lógica de anti-duplicado o cooldown
- Se descubra un comportamiento nuevo de Twilio

---

### `docs/qa-manual.md`
**Para qué sirve:** checklist de pruebas manuales para validar que todo funciona antes de entregar o después de un cambio grande

**Sí va aquí:**
- Casos de prueba happy path por módulo
- Casos borde (opt-out, cooldown, error API, etc.)
- Flujos de regresión a verificar manualmente

**No va aquí:**
- Tests automáticos (esos viven en `tests/`)
- Decisiones de arquitectura
- Estado de features completadas

**Actualizar cuando:**
- Se agregue un feature nuevo que requiera prueba manual
- Se descubra un bug que debió haberse detectado con QA manual
- Cambie el flujo de un feature existente

---

### `.claude/rules/contexto-meta-whatsapp.md`
**Para qué sirve:** conocimiento acumulado sobre Meta / WhatsApp Cloud API — decisiones, lecciones, restricciones y comportamientos no obvios

**Sí va aquí:**
- Cómo funciona el warm-up y los tiers
- Cómo Meta normaliza números en México (521 → 52)
- Códigos de error y qué hacer con cada uno
- Por qué elegimos ciertas decisiones (ej: opt-out exact match)
- Cambios pendientes de Meta (ej: usernames deadline junio 2026)

**No va aquí:**
- Estado de features implementadas
- Guías para el operador (eso va en `docs/guia-operador.md`)
- Pitfalls de código PHP/Laravel (eso va en `MEMORY.md`)
- Información de SMS/Twilio (eso va en `contexto-twilio-sms.md`)

**Actualizar cuando:**
- Se descubra un comportamiento nuevo de Meta no documentado
- Se tome una decisión importante relacionada con la API
- Meta anuncie un cambio relevante

---

### `.claude/rules/contexto-twilio-sms.md`
**Para qué sirve:** conocimiento acumulado sobre Twilio / SMS — errores, opt-out automático, carrier fees, cumplimiento legal México, cooldown cross-channel, configuraciones recomendadas

**Sí va aquí:**
- Tabla de errores Twilio y acciones automáticas (21610, 30004, 30005, etc.)
- Reglas de auto-blacklist por rebotes consecutivos
- Diferencias de horario entre WA (forzado) y SMS (libre)
- Cumplimiento legal SMS México (LFPDPPP, REPEP, opt-out en mensaje)
- Protección anti-duplicado cross-channel (ventana de exclusión)
- Config de desarrollo (trial, mocks, testing)
- Decisiones pendientes (CFDI, ventana exclusión, límite semanal)

**No va aquí:**
- Estado de features implementadas (va en calendario)
- Información de WhatsApp/Meta (va en `contexto-meta-whatsapp.md`)
- Pitfalls de código PHP/Laravel (va en `MEMORY.md`)

**Actualizar cuando:**
- Se descubra un error Twilio nuevo o cambie el comportamiento de uno existente
- Cambie la regulación SMS en México
- Se tome una decisión sobre el proveedor (Twilio vs SMS Masivos)
- Se ajuste la ventana de exclusión o el cooldown

---

### `docs/guia-operador.md`
**Para qué sirve:** manual de usuario para el equipo de Prestamaz — lo que el operador necesita saber para usar el sistema día a día

**Sí va aquí:**
- Cómo usar cada pantalla del panel
- Qué significan los chips, estados y botones
- Flujos de trabajo (crear campaña WA o SMS, gestionar conversaciones, etc.)
- Guías operacionales de Meta (cómo agregar número de prueba, cómo renovar token, etc.)
- FAQ del operador

**No va aquí:**
- Detalles técnicos de implementación
- Reglas de código o arquitectura
- Pitfalls internos de desarrollo

**Actualizar cuando:**
- Se agregue un feature visible en la UI
- Cambie el comportamiento de algo ya documentado
- Se renombre algo en la UI (labels, secciones, rutas)

---

### `docs/arquitectura-referencia.md`
**Para qué sirve:** cómo está construido el sistema técnicamente + por qué se eligió cada tecnología

**Sí va aquí:** estructura de carpetas Laravel, flujo de una petición, tabla de archivos clave, rutas documentadas, tabla de decisiones tecnológicas (Laravel vs Symfony, MySQL vs PostgreSQL, etc.)
**No va aquí:** estado de features, reglas de código
**Actualizar cuando:** se agregue un servicio nuevo, cambie la estructura, se agreguen rutas importantes, se tome una decisión tecnológica nueva

---

### `docs/testing.md`
**Para qué sirve:** guía interna de cómo escribir tests en este proyecto

**Sí va aquí:** convenciones de nombres, Feature vs Unit, cómo mockear WhatsAppClient y SmsClient, configuración phpunit
**No va aquí:** tests automáticos reales (en `tests/`), casos de prueba manuales (en `docs/qa-manual.md`)
**Actualizar cuando:** cambie la convención de tests o la configuración de phpunit

---

### `docs/deploy-vps.md`
**Para qué sirve:** receta paso a paso para desplegar en VPS Ubuntu — Nginx, PHP-FPM, SSL, Supervisor

**Sí va aquí:** instalación, configuración SSL, variables de entorno de producción, comandos de deploy
**No va aquí:** estado del proyecto, reglas de código
**Actualizar cuando:** cambie el proceso de deploy o se agregue un servicio nuevo (Redis, Horizon)

---

### `.claude/commands/lineamientos.md`
**Para qué sirve:** checklist obligatorio que Claude DEBE leer y aplicar antes de cualquier cambio de código

**Sí va aquí:** qué verificar antes y al terminar (guía operador, tests, seguridad, API, versionado)
**Actualizar cuando:** se establezca un nuevo paso obligatorio en el flujo de desarrollo

---

### `.claude/commands/nueva-feature.md`
**Para qué sirve:** checklist específico al arrancar un feature nuevo — antes, durante y al terminar

**Sí va aquí:** pasos antes (correr tests, revisar calendario, seguridad), durante (test antes del código), al terminar (verde, commit)
**Actualizar cuando:** cambie el flujo de desarrollo de features

---

### `.claude/commands/test.md`
**Para qué sirve:** instrucciones del comando `/test` — cómo correr la suite completa y qué reportar
**Actualizar cuando:** cambie el comando o la configuración de tests

---

### `.claude/rules/seguridad.md`, `estilo-codigo.md`, `convenciones-api.md`, `proteccion-cuenta-meta.md`
**Para qué sirven:** reglas permanentes que Claude debe aplicar en cada cambio de código

**Sí va aquí:**
- Reglas inquebrantables de seguridad
- Convenciones de código que aplican siempre
- Checklist antes de tocar envíos o tokens

**No va aquí:**
- Estado del proyecto
- Pitfalls puntuales de una sesión

**Actualizar cuando:**
- Se establezca una regla nueva que deba aplicarse siempre
- Se corrija una regla por una lección aprendida importante

---

### `MEMORY.md` (en `.claude/projects/.../memory/`)
**Para qué sirve:** memoria operativa entre sesiones de Claude — pitfalls reales, bugs resueltos, lecciones que no queremos repetir, preferencias del usuario

**Sí va aquí:**
- Pitfalls técnicos: "data_get con wildcards anida un nivel extra, usar Arr::collapse"
- Bugs resueltos que podrían volver: "WhereDate() con UTC rompe filtros de fecha"
- Preferencias del usuario: "el usuario prefiere explicaciones con contexto de Laravel"
- Estado de entorno: "4 terminales necesarias para dev"
- Decisiones de diseño no obvias: "opt-out exact match, no regex"

**No va aquí:**
- Estado de features (va en `docs/calendario-entregas.md`)
- Backlog y pendientes (va en `docs/calendario-entregas.md`)
- Checklist QA (va en `docs/qa-manual.md`)
- Guías para el operador (va en `docs/guia-operador.md`)
- Historia completa del proyecto

**Actualizar cuando:**
- Aparezca un bug sutil o pitfall técnico recurrente
- Se descubra una diferencia importante entre entornos
- El usuario corrija un comportamiento de Claude que debería mantenerse
- Haya una lección operativa que ayude en sesiones futuras

---

## Cómo se enlazan entre sí

```
CLAUDE.md                    ← punto de entrada, reglas y referencias
  ├── docs/calendario-entregas.md     ← qué existe hoy y qué falta
  ├── docs/guia-operador.md           ← cómo usa el sistema el operador
  ├── docs/qa-manual.md               ← cómo validar que todo funciona
  ├── docs/arquitectura-referencia.md ← cómo está construido
  ├── docs/sms-referencia.md          ← arquitectura multicanal SMS
  ├── .claude/rules/*.md              ← reglas permanentes para Claude
  ├── .claude/rules/contexto-meta-whatsapp.md ← conocimiento acumulado Meta
  └── .claude/rules/contexto-twilio-sms.md    ← conocimiento acumulado Twilio/SMS

MEMORY.md  ← no parte del repo — pitfalls y lecciones entre sesiones de Claude
```

En resumen:
- `CLAUDE.md` = cómo se trabaja y referencias
- `docs/calendario-entregas.md` = qué existe hoy
- `docs/sms-referencia.md` = cómo funciona el canal SMS
- `docs/guia-operador.md` = cómo lo usa el operador
- `docs/qa-manual.md` = cómo se valida
- `.claude/rules/` = reglas permanentes de código
- `.claude/rules/contexto-meta-whatsapp.md` = qué sabemos de Meta
- `.claude/rules/contexto-twilio-sms.md` = qué sabemos de Twilio/SMS
- `MEMORY.md` = qué no debemos olvidar entre sesiones

---

## Regla práctica — qué actualizar según el tipo de cambio

| Tipo de cambio | Archivo a actualizar |
|---|---|
| Se completa un feature / hay nuevo pendiente | `docs/calendario-entregas.md` |
| Cambia algo visible en la UI para el operador | `docs/guia-operador.md` + popover en `AppLayout.vue` |
| Cambia cómo validar manualmente un flujo | `docs/qa-manual.md` |
| Cambia la estructura técnica o se toma una decisión tecnológica | `docs/arquitectura-referencia.md` |
| Cambia el flujo multicanal, cooldown o anti-duplicado | `docs/sms-referencia.md` |
| Cambia el proceso de deploy | `docs/deploy-vps.md` |
| Cambia la convención de tests | `docs/testing.md` |
| Se descubre comportamiento no obvio de la API Meta | `.claude/rules/contexto-meta-whatsapp.md` |
| Se descubre error Twilio, cambia regulación SMS, o se ajusta cooldown | `.claude/rules/contexto-twilio-sms.md` |
| Se establece una regla de código permanente | `.claude/rules/seguridad.md` / `estilo-codigo.md` / `convenciones-api.md` |
| Hay riesgo nuevo sobre la cuenta Meta | `.claude/rules/proteccion-cuenta-meta.md` |
| Cambia el flujo obligatorio antes de codear | `.claude/commands/lineamientos.md` |
| Cambia el flujo de arranque de un feature | `.claude/commands/nueva-feature.md` |
| Aparece un pitfall técnico o lección de sesión | `MEMORY.md` |
| Cambia el stack o una referencia global | `CLAUDE.md` |

Si un cambio toca varias capas → actualizar todos los que correspondan, nunca forzar todo en uno solo.

---

## Comando operativo: `actualiza contextos`

Cuando se diga `actualiza contextos`, el flujo correcto es:

1. Leer este `context-map.md`
2. Revisar los cambios recientes del proyecto
3. Decidir qué contexto corresponde actualizar según la tabla de arriba
4. Actualizar solo los archivos necesarios
5. Mantener consistencia y eliminar contradicciones

No actualizar un archivo por inercia si no hubo cambios de ese tipo.

---

## Prioridad si hay conflicto

- `.claude/rules/*.md` manda en reglas de código permanentes
- `docs/calendario-entregas.md` manda en estado actual del producto
- `.claude/rules/contexto-meta-whatsapp.md` manda en comportamiento de Meta
- `.claude/rules/contexto-twilio-sms.md` manda en comportamiento de Twilio/SMS
- `MEMORY.md` complementa, pero no reemplaza a los otros

Si `MEMORY.md` contradice algo estable ya confirmado en otro archivo → corregir `MEMORY.md`.

---

## Meta de mantenimiento

El objetivo no es documentar todo.
El objetivo es que, después de cambiar de proyecto y volver días después, puedas decir:

`actualiza contextos`

y Claude sepa dónde mirar, qué actualizar y qué no mezclar.
