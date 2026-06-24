# Calendario de entregas

---

## Sección 1: Entregas al cliente (lo que él ve y toca)

Estas son las demos visuales. El cliente no necesita saber los detalles técnicos.

### Entrega 1 — Panel en producción + gestión de contactos

- Cliente entra a URL real con login (su marca)
- Sube Excel de contactos
- Ve dashboard con estadísticas de contactos cargados
- Todo en HTTPS con SSL
- **Momento wow**: "Ya tengo mi sistema en línea, con mi login, ya subí mis contactos"
- *Nota interna: el VPS queda montado desde aquí — nos ahorra estrés después*
- ⚠️ **Los envíos NO van en producción en esta entrega** — solo carga de contactos

### Entrega 2 — Demo WhatsApp en vivo

- Demo corre en **local** (máquina del dev), no en VPS del cliente
- Selecciona plantilla aprobada
- Le pedimos SU número de celular en la demo
- Le damos "enviar" → le llega el WhatsApp a su celular en ese momento
- Ve en el panel que se envió y entregó
- **Momento wow**: "Me llegó al celular, es real, esto funciona"
- *Nota interna: demo controlada. Usar cuenta Meta del dev hasta Stage 3. Business Manager, System User y plantillas aprobadas listas antes*

### Entrega 3 — Métricas + inteligencia del sistema

- Dashboard con gráficas envío por día/hora
- Semáforo verde/amarillo/rojo calidad del número
- Lista negra automática cuando alguien pide baja
- Reportes descargables Excel
- **Momento wow**: "El sistema me cuida solo, bloquea a los que no quieren"

### Entrega 4 — Software completo + capacitación

- Campañas masivas reales enviándose
- Multi-número con balanceo
- Respuestas entrantes en el panel
- Guía impresa de operador
- Sesión de capacitación con equipo + video grabado
- **Momento wow**: "Mi equipo ya sabe usarlo, esto está en producción"

---

## Sección 2: Plan de desarrollo (interno, no compartir con cliente)

### Etapa 1 (alimenta Entregas 1 y 2)

- [ ] Deploy básico VPS: Ubuntu + Nginx + PHP-FPM + SSL + MySQL ← **pendiente server**
- [x] Auth Laravel (login/registro) — Sanctum + roles superadmin/admin/operator/agent
- [x] Feature gating por etapa — 10 flags reactivos (módulos + sub-features), presets E1-E4, superadmin bypasa siempre
- [x] Upload Excel (PhpSpreadsheet)
- [x] ContactController: index, stats, upload, optOut
- [x] Dashboard con conteos básicos — stats mensajes + contactos + tabla recientes
- [x] Job `SendWhatsAppMessage` con queue database
- [x] CampaignController: crear, listar, ejecutar
- [x] Scheduler horario legal: **ventana 9AM-10PM America/Mexico_City (CST/UTC-6)**
- [x] Warm-up limits automáticos por tier (en el Job, daily_limit por número)
- [x] Tests Feature: 118 tests, 317 assertions — suite completa verde
- [x] ⚠️ Paralelo: `prestamaz_interes_v1` aprobada en Meta
- [x] ⚠️ Paralelo: System User token permanente configurado (`waclouddev`)

### Etapa 2 (alimenta Entrega 3)

- [x] Dashboard métricas tiempo real (enviados, entregados, leídos, fallidos)
- [x] Gráficas envío por día/hora — Chart.js + PrimeVue, serie 14 días, endpoint `/api/dashboard/daily-stats`
- [x] Opt-out automático STOP/NO/BAJA/CANCELAR
- [x] Feedback visual calidad número (semáforo) — widget en dashboard
- [x] Circuit breaker por calidad — `paused_until` en phone_numbers, error 131048/368
- [x] Export reportes Excel — `/api/export/contacts` y `/api/export/messages`
- [x] Snooze de contactos — botón "No por ahora" activa cooldown configurable
- [x] Conversaciones — chat entrante/saliente, ventana 24h, respuestas rápidas
- [x] Sincronización plantillas con Meta API
- [x] Tests integración completos — ConversationController, Export, Settings, Webhook, etc.
- [x] 📘 Guía de operador — ayuda contextual por sección (popover `?` en topbar) + `docs/guia-operador.md`

### Etapa 3 (alimenta Entrega 4)

- [x] Detalle y control de campañas — logs por contacto, discard_reason, pause, delete, auto-completar campaña
- [ ] Redis + Laravel Horizon
- [x] Multi-número con balanceo inteligente — PhoneNumberSelector distribuye en round-robin por capacidad restante
- [x] Tags y segmentación de contactos
- [x] Multi-agente de atención — auto-asignación, claim, assign, modos least_chats/first_available, chip Sin asignar, resaltado propias
- [ ] Deploy script automatizado
- [ ] Monitoreo + alertas
- [ ] Tests regresión automatizados (CI GitHub Actions)
- [ ] ⚠️ Warm-up número producción (3-4 semanas paralelas)
- [x] 📘 Guía completa operador — sección guías operacionales Meta (agregar número prueba, registrar número nuevo, renovar token, interpretar alertas Business Manager)
- [ ] 📋 QA manual completo — ejecutar `docs/qa-manual.md` y corregir bugs encontrados
- [ ] 📘 Sesión capacitación + video grabado

### Backlog técnico (no bloquea Entrega 4, priorizar según crecimiento de BD)

- [x] **Optimización de queries para escala** — N+1 en ConversationController (window_open → withExists), ContactController::stats (4 queries → 1 GROUP BY)
- [x] **Política de sesiones Sanctum** — tokens expiran a las 8h, sanctum:prune-expired corre diario
- [x] **Índices de BD** — índice compuesto (contact_id, id) en conversation_assignments; índices previos en message_log y contacts.status ya existían
- [ ] **Detección de contactos inalcanzables (`unreachable`)** — ver plan completo en [`docs/plan-unreachable.md`](plan-unreachable.md). Comando `wa:mark-unreachable` corre diario a las 6AM CST (antes de la ventana de envíos). Marca contactos `active` con 2+ mensajes en `sent` de 30+ días sin ningún `delivered`/`read` histórico. Protege el ratio `delivered/sent` y la calidad del número en Meta.
- [ ] **Auto-sincronizar `daily_limit` cuando Meta sube el tier** — actualmente el `daily_limit` en BD se actualiza solo manualmente (botón Salud del número en Settings). Cuando Meta promueve el tier automáticamente, el PhoneNumberSelector sigue usando el límite viejo hasta que alguien presione el botón. Solución: el job de envío o un comando `wa:sync-limits` debería llamar al endpoint de Meta y actualizar `phone_numbers.daily_limit` si cambió.

### Features E2 (pendientes)

- [ ] **Total contactos en modal campaña** — mostrar "0/N contactos" en modal de detalle aunque la campaña no haya corrido. Actualmente muestra "Sin registros" si no hay logs.
- [ ] **Asignación masiva de tags** — seleccionar varios contactos en la tabla y asignar un tag a todos de una vez, sin ir 1 por 1.
- [ ] **Alta individual de contacto** — formulario para agregar un contacto manualmente sin necesidad de Excel.
- [ ] **Eliminar contactos** — soft delete (marcar como inactivo, no borrar físicamente — ver nota abajo).
- [ ] **Borrar plantillas jaspers del panel** — limpiar plantillas de demo/prueba antes de mostrar al cliente.
- [ ] **Tooltip en "Lista de contactos"** — ícono ? junto al título de la tabla que explique estados (activo/opted_out/inválido/snooze), fuente del contacto (excel/manual), y origen de baja (auto/manual).

### Bugs y mejoras UI (pendientes)

- [ ] **Homologar botones** — hay inconsistencias entre vistas (ej. Contactos usa estilos distintos al editar y las pills/badges de tags varían). Crear componente base o definir uso estricto de PrimeVue Button según la tabla del estilo-codigo.md.
- [ ] **Responsive mobile** — Dashboard y Contactos no funcionan adecuadamente en móvil. Revisar layout de cards de stats, tabla de últimos mensajes y tabla de contactos en pantallas < 768px.
- [ ] **Pills de estado en tabla "Últimos mensajes"** — agregar icono `ⓘ` con tooltip explicando cada estado posible (en tránsito, entregado, leído, fallido) para que el operador entienda el semáforo sin ir al Help.
- [ ] **Actualizar popovers de ayuda** — revisar todos los `helpContent` en `AppLayout.vue` al finalizar cada entrega y alinearlos con el comportamiento real del sistema.
- [ ] **Mejorar pantalla de login** — rediseñar al estilo Doters Admin (más visual, con branding, fondo con gradiente o imagen, card centrada con sombra más pronunciada).
- [ ] **Consola limpia** — suprimir el warning de i18next que aparece en consola del navegador. Agregar mensaje de firma de NodosMX (ej. `console.log` estilizado con CSS) como branding de desarrollo.
