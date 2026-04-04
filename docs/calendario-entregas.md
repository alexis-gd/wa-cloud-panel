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
- [x] Auth Laravel (login/registro) — Sanctum + roles admin/operator/agent
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
- [ ] Multi-número con balanceo inteligente — phone_numbers con quality_rating y circuit breaker ya existen, falta lógica de selección óptima en job
- [x] Tags y segmentación de contactos
- [x] Multi-agente de atención — auto-asignación, claim, assign, modos least_chats/first_available, chip Sin asignar, resaltado propias
- [ ] Deploy script automatizado
- [ ] Monitoreo + alertas
- [ ] Tests regresión automatizados
- [ ] ⚠️ Warm-up número producción (3-4 semanas paralelas)
- [ ] 📘 Guía completa operador — sección guías operacionales Meta (agregar número prueba, registrar número nuevo, renovar token, interpretar alertas Business Manager)
- [ ] 📋 QA manual completo — ejecutar `docs/qa-manual.md` y corregir bugs encontrados
- [ ] 📘 Sesión capacitación + video grabado

### Backlog técnico (no bloquea Entrega 4, priorizar según crecimiento de BD)

- [ ] **Optimización de queries para escala** — revisar N+1 en ContactController (index + stats), ConversationController (index con subquery MAX assignments), DashboardController (daily-stats group by), MessageLog (logs de campaña sin índices)
- [ ] **Política de sesiones Sanctum** — tokens actualmente sin expiración; definir si se quiere TTL automático
- [ ] **Índices de BD** — agregar índices en columnas de filtro frecuente: `contacts.status`, `message_log.sent_at`, `conversation_assignments.contact_id + id`ador
- [ ] 📘 Sesión capacitación + video grabado
