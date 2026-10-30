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
- [x] Opt-out automático por texto (lista vigente: STOP / DAR DE BAJA)
- [x] Feedback visual calidad número (semáforo) — widget en dashboard
- [x] Circuit breaker por calidad — `paused_until` en phone_numbers, error 131048/368
- [x] Export reportes Excel — `/api/export/contacts` y `/api/export/messages`
- [x] Snooze de contactos — botón "No por ahora" activa cooldown configurable
- [x] Conversaciones — chat entrante/saliente, ventana 24h, respuestas rápidas
- [x] Sincronización plantillas con Meta API
- [x] Tests integración completos — ConversationController, Export, Settings, Webhook, etc.
- [x] 📘 Guía de operador — ayuda contextual por sección (popover `?` en topbar) + `docs/guia-operador.md` (retirado 2026-07-08 → hoy `docs/guias/guia-uso.md` + `guia-meta.md`)

### Etapa 3 (alimenta Entrega 4)

- [x] Detalle y control de campañas — logs por contacto, discard_reason, pause, delete, auto-completar campaña
- [ ] Redis + Laravel Horizon — solo cuando el volumen sea alto (hoy cola `database` alcanza). Redis = cola en memoria (más rápida); Horizon = panel para verla en vivo. Fase de escalado real.
- [x] Multi-número con balanceo inteligente — PhoneNumberSelector distribuye en round-robin por capacidad restante
- [x] Tags y segmentación de contactos
- [x] Multi-agente de atención — auto-asignación, claim, assign, modos least_chats/first_available, chip Sin asignar, resaltado propias
- [x] Deploy script automatizado — `deploy.sh` en la raíz (1 comando: mantenimiento + pull + deps + build + migrate + cache + restart queue + health check)
- [ ] Monitoreo + alertas
- [ ] Tests regresión automatizados (CI GitHub Actions)
- [ ] ⚠️ Warm-up número producción (3-4 semanas paralelas)
- [x] 📘 Guía completa operador — sección guías operacionales Meta (agregar número prueba, registrar número nuevo, renovar token, interpretar alertas Business Manager)
- [x] 📋 QA / capacitación — reemplazados por las **guías** (`guia-uso.md` operador + `guia-meta.md` admin, compiladas a HTML). El modo de entrega cambió: se le explicó al intermediario con las guías y él trata con su cliente. Video descartado.

### Backlog técnico (no bloquea Entrega 4, priorizar según crecimiento de BD)

- [x] **Optimización de queries para escala** — N+1 en ConversationController (window_open → withExists), ContactController::stats (4 queries → 1 GROUP BY)
- [x] **Política de sesiones Sanctum** — tokens expiran a las 8h, sanctum:prune-expired corre diario
- [x] **Índices de BD** — índice compuesto (contact_id, id) en conversation_assignments; índices previos en message_log y contacts.status ya existían
- [x] **Detección de contactos inalcanzables (`unreachable`)** — ver plan completo en [`docs/plan-unreachable.md`](plan-unreachable.md). Comando `wa:mark-unreachable` corre diario a las 6AM CST (antes de la ventana de envíos). Marca contactos `active` con 2+ mensajes en `sent` de 30+ días sin ningún `delivered`/`read` histórico. Protege el ratio `delivered/sent` y la calidad del número en Meta. **Bloque A** (migración + comando + scheduler + check en job + tests) y **Bloque B** (v0.18.0: widget dashboard, reactivación manual admin, label en Contactos, guía+popovers) HECHOS.
- [x] **Warm-up/tier automático + freno del portfolio** (2026-07-08) — el límite de la cuenta lo pone Meta (por **portfolio**, compartido); se lee en `phoneHealth` (`whatsapp_business_manager_messaging_limit`; `messaging_limit_tier` deprecado) y se guarda en `Setting wa_portfolio_daily_limit`. Comando `wa:warmup-numbers` (scheduler 05:00 CST) sube el `daily_limit` de cada número que usó ≥50% ayer, ×2, topado por el portfolio; `PhoneNumber::backOffDailyLimit()` lo baja a la mitad (piso 250) al pausar por calidad/spam (131048/131064/368). El job frena si el total del día alcanza el techo del portfolio. Dashboard: capacidad = `min(portfolio, suma)`. Helper `App\Services\WhatsApp\PortfolioLimit`.

### Features E2 (pendientes)

- [x] **Total contactos en modal campaña** — muestra "0/N contactos" antes de ejecutar (snapshot al crear + recálculo en vivo para draft). Rama `feat/campaign-total-contacts`.
- [x] **Asignación masiva de tags** — selección múltiple + barra de acción: asignar, quitar y crear tag al vuelo. Rama `feat/contacts-bulk-tags`.
- [x] **Eliminar contactos** — soft delete (`deleted_at`), solo admin/superadmin, separado del opt-out. Para limpiar basura/pruebas. Rama `feat/contacts-soft-delete`.
- [x] **Borrar plantillas jaspers del panel** (2026-07-08) — plantillas de demo/prueba ya apagadas; el panel solo muestra las aprobadas reales.
- [x] **Tooltip en "Lista de contactos"** — ícono ? explica Estado vs Entregabilidad. + columna **Entregabilidad** (Disponible / En cooldown / Enviado hoy / No recibe). Rama `feat/contacts-deliverability`.
- [x] **Alta individual de contacto** — formulario manual + chequeo de estado en vivo. Rama `feat/contact-manual-add`.

### Bugs y mejoras UI (pendientes)

- [ ] **Homologar botones (auditar)** — no urgente, pero no se pierde nada auditando: revisar inconsistencias entre vistas (ej. Contactos usa estilos distintos al editar y las pills/badges de tags varían) contra la tabla de `estilo-codigo.md`.
- [x] **Responsive mobile (arreglado 2026-07-09, pendiente validar en móvil)** — fix en las 6 vistas que desbordaban: (1) `.main`/`.content` con `min-width:0` en `AppLayout` (base para que el contenido ancho no estire la página); (2) cada tabla envuelta en `.table-scroll { overflow-x:auto }` (Contactos, Campañas, Respuestas SMS, Usuarios, Plantillas) → scrollea en su caja sin cortar la página; (3) `@media` para apilar stat-cards/filtros; (4) Conversaciones pasa a **master-detail** en móvil (lista → chat con botón atrás, panel de info oculto). Build OK. Ver [[project_responsive_design]].
- [ ] **Pills de estado + tooltips (auditar)** — repaso opcional de tooltips/`helpContent` de estados en tablas; alinearlos con el comportamiento real. No se pierde nada auditando.
- [ ] **Popovers de ayuda (auditar)** — repaso opcional de los `helpContent` en `AppLayout.vue` para alinearlos con cada entrega. No urgente.
- [x] **Pantalla de login** (rediseñada hace tiempo) — split-panel navy/blanco full-viewport. Ver MEMORY (Frontend).
- [ ] **Consola limpia** — suprimir el warning de i18next que aparece en consola del navegador. Agregar mensaje de firma de NodosMX (ej. `console.log` estilizado con CSS) como branding de desarrollo.

### Canal SMS — SIM propia vía Android Gateway (✅ EN PRODUCCIÓN, probado 2026-07-02)

> El cliente rechazó pagar una API de proveedor (Twilio). Decisión: enviar SMS por **SIM propia**
> usando **SMS Gateway for Android™** (capcom6) en modo self-host. El cliente asume los riesgos
> (bloqueo de SIM por operador, entrega no auditable, mantenimiento). Ver
> [`docs/sms-sim-propia-analisis.md`](sms-sim-propia-analisis.md) y el setup real en
> [`docs/guia-sms-gateway-setup.md`](guia-sms-gateway-setup.md). Mergeado a `main`.

- [x] **Migraciones multicanal** — `channel` en `message_log` y `campaigns`; columnas WA (`template_name`, `language_code`, `phone_number_id`) ahora nullable; campos `sms_opt_out`, `sms_blocked`, `sms_invalid`, `sms_bounce_count` en `contacts`.
- [x] **`SmsGatewayClient`** — único punto de salida HTTP al gateway (espejo de `WhatsAppClient`, config en `config/sms.php`). Antepone `+` (E.164) que el gateway exige.
- [x] **Job `SendSmsMessage`** — separado del job WA; opt-out y blacklist **cross-channel**; dedup, cooldown **y snooze por canal** (snooze es solo WhatsApp, v0.27.0); SMS **sin horario forzado** (el cliente elige cuándo).
- [x] **Campaña por canal** — selector WhatsApp/SMS en el modal; el pool de chips lo resuelve el gateway (sin selector de número).
- [x] **Botón "Enviar prueba"** (admin) — dispara 1 SMS al gateway sin crear campaña ni cooldown.
- [x] **Gateway desplegado en prod** — Docker en el VPS, expuesto por **Cloudflare Tunnel** (`gw.prestamaz.site`), 1 teléfono (SIM Telcel) registrado. **Primer SMS OK vía botón + campaña.**
- [x] **Webhook `POST /api/sms/webhook`** (✅ validado 2026-07-02) — 4 eventos registrados en el gateway; `sms:delivered` actualiza a "Entregado", `sms:received` STOP marca opt-out; `failed`→rebote (3⇒`sms_blocked`). Payload real: estados usan `messageId`, entrantes `sender`. **Gotcha**: el teléfono debe reiniciarse para re-sincronizar la lista de webhooks tras registrarlos.
- [~] **Firma del webhook (`SMS_WEBHOOK_SECRET`)** — NO es pendiente (decisión). Endurecimiento opcional futuro: HMAC-SHA256(body+X-Timestamp), ~10 min (secreto en `.env` + `config:cache` + misma Signing Key en cada teléfono). Hoy vacío = sin verificación de firma; riesgo bajo (la URL no es conocida). Se retoma solo si se quiere blindar antes de prod formal.
- [x] **(A) Manejo de números SMS diferenciado — auto-blacklist configurable**: nuevo `Setting sms_auto_blacklist_bounces` (**default 0 = nunca bloquea**), editable en Configuración (solo superadmin). `Contact::registerSmsBounce()` sigue contando rebotes (para reporte) pero solo pone `sms_blocked` si el umbral > 0 y se alcanza. Razón: SIM propia barata → al cliente no le importa la reputación del número; "no suma fallas". **Opt-out (STOP) se queda igual (legal, LFPDPPP vigente 21-mar-2025)**. WhatsApp mantiene su bloqueo estricto.
- [x] **(B) SMS entrantes visibles — "Respuestas SMS"**: los `sms:received` se guardan en `sms_inbound_messages`. Vista `SmsRepliesView` + endpoint `GET /api/sms/inbound` (admin/operator). Solo muestra respuestas de CONTACTOS (`whereNotNull('contact_id')`, esconde ruido de operadora tipo UNOTV/TELCEL). **Evolucionado (v0.25.0)**: vista **AGRUPADA por contacto** (fila expandible con todos sus mensajes) + **detección de interés** (SI/INFO → tag verde "Interesado") además de STOP / DAR DE BAJA → "Baja automática". Filtro Todas/Interesados/Bajas.
- [x] **(C) Badge "SMS baja" en Contactos**: chip rojo "Baja SMS" bajo el Estado cuando `sms_opt_out`/`sms_blocked`/`sms_invalid`, separado del "Estado" (WhatsApp). Tooltip con el motivo (nota: no guardamos fecha/origen del opt-out SMS, solo el motivo). Filtro "Solo bajas SMS" (`?sms_blocked=1` en `/api/contacts`). Los flags `sms_*` ya viajaban en el index del modelo.
- [x] **Plantillas de SMS locales**: tabla `sms_templates` (nombre + cuerpo + activa), no pasan por Meta. Se administran en la vista **Plantillas** (pestaña SMS): listar, crear/editar, activar/desactivar, vista previa, enviar prueba (reusa `POST /api/sms/send-test`). Endpoints `GET /api/sms-templates` (admin/operator) + `POST/PUT/DELETE` (admin). Componente `SmsTemplatesPanel.vue`.
- [x] **Campaña SMS solo por plantilla (sin texto libre)**: la campaña SMS exige `sms_template_id` (no `sms_body` libre) - garantiza que se usó una plantilla revisada, igual que WhatsApp exige plantilla aprobada. El cuerpo se snapshotea en `sms_body` al crear (el envío no cambia si la plantilla se edita/borra). Migración `sms_template_id` en `campaigns` (FK nullOnDelete). La tabla Campañas muestra icono + nombre de plantilla SMS (paridad con WhatsApp). El textarea libre se eliminó; "Enviar prueba" usa el cuerpo de la plantilla elegida.
- [x] **Salud del webhook SMS + alerta**: card en Configuración que diagnostica el canal de vuelta (registra cada llegada al endpoint, las rechazadas por firma y el último OK) → distingue "firma" vs "sin llegadas" vs "ok". Comando `sms:monitor-webhook` (cada 15 min) alerta en la campana si se envía pero no vuelve nada; se auto-resuelve al recibir un evento. **Nota capcom6**: los webhooks los entrega el TELÉFONO (no el server; `fcm` vacío, sin worker server-side); se desincroniza al registrar webhooks nuevos o si MIUI mata la app (fix: Autostart ON + batería sin restricción + bloquear en recientes).
- [x] **Polling de estado SMS (red de seguridad)**: `SmsGatewayClient::getState()` + comando `sms:reconcile-status` (cada 10 min) consulta al gateway el estado de los SMS en 'sent' y los pasa a delivered/failed **sin depender del webhook** (server-a-server). Cubre solo estado de entrega.
- [x] **Reconcile de entrantes (red de seguridad)**: `SmsGatewayClient::requestInboxExport()` + comando `sms:reconcile-received` (cada hora) le pide al teléfono re-exportar los `sms:received` de las últimas 24h vía `POST {url}/messages/inbox/export`. Los mensajes vuelven por el mismo webhook y se deduplican por `sms_inbound_messages.gateway_message_id` (evita filas y opt-outs repetidos). Recupera respuestas perdidas si MIUI mató la app. Async (dispara la exportación, no la lee).
- [~] **Rate limit por SIM** — NO es tarea de desarrollo: el gateway/operadora ya limita a ~8 SMS/min por chip. Solo afinarlo en el servidor gateway si hiciera falta. Fuera del panel.
- [~] **Feature flag `sms_campaigns`** — descartado salvo que se necesite: solo serviría para ocultar el canal SMS a un cliente que no lo contrató. Prestamaz sí usa SMS → innecesario.
- [~] **Setup físico prod (responsabilidad del cliente)** — escalar de 1 a 5-8 celulares + SIMs lo hace el cliente. Pasos documentados en la **guía Meta** (sección 6, "Agregar teléfonos para enviar SMS") + [docs/guia-sms-gateway-setup.md](guia-sms-gateway-setup.md).

### Tanda tiempo real + refinamiento (v0.19–v0.28, ✅ en prod)

Transporte **Soketi** (WebSocket compatible Pusher, Docker en el VPS). Patrón: evento `ShouldBroadcast` + punto que lo dispara + listener en la vista. Detalle en [`docs/plan-realtime.md`](plan-realtime.md).

- [x] **Conversaciones en vivo** (v0.19) — `InboundMessageReceived` → canal `conversations`. Base echo.js + BroadcastServiceProvider (auth Sanctum).
- [x] **Campañas en vivo** (v0.20) — `CampaignProgressUpdated` → mata el polling de 5s del modal; fila + modal + detalle suben solos (throttle en blasts).
- [x] **Campanita en vivo** (v0.21) — `NotificationCreated` (hook del modelo) → mata el polling de 30s.
- [x] **Dashboard en vivo** (v0.22) — `PhoneNumberPaused` (semáforo) + refetch-on-event debounced. **CERO pollings** logrado.
- [x] **Respuestas SMS en vivo** (v0.23) — `InboundMessageReceived` con `channel='sms'`.
- [x] **Ciclo de entrega en vivo WA+SMS** (v0.24) — los webhooks emiten `CampaignProgressUpdated` al cambiar status → Enviado→Entregado→Leído→Fallido sin reabrir.
- [x] **Respuestas SMS agrupadas + interés** (v0.25) — 1 fila por contacto expandible; tag "Interesado" (SI/INFO) además de "Baja automática".
- [x] **Nav por rol** (v0.25.1) — el agente queda encerrado en Conversaciones (router + nav); backend ya daba 403. Solo rol `agent` recibe auto-asignación.
- [x] **Conversaciones: estado + asignación en vivo** (v0.26) — `ConversationUpdated` (assign/claim/send + webhook). Rediseño visual: chip de estado (Abierta/Cerrada/Snooze/Baja) separado de asignación (Sin asignar/Tú/iniciales); borde verde = solo "mía".
- [x] **Snooze por canal** (v0.27) — el "No por ahora" (botón WhatsApp) pausa **solo WhatsApp**; SMS no lo respeta. Demo reset limpia snooze (v0.27.1).
- [x] **Seeders + limpieza** (v0.28) — `migrate:fresh --seed` deja BD usable (5 usuarios @prestamaz.mx + 4 contactos + número). Comando `db:clean-demo` (flags `--contacts`/`--users`) limpia datos de prueba sin tocar plantillas/config. Ver [`docs/limpieza-y-seeds.md`](limpieza-y-seeds.md).
- [x] **"Etapas de entrega" desactivado** (v0.28) — el control de feature flags queda oculto (const `stageControlEnabled=false`) para no apagar módulos por error. Footer sin "Stage 3".

### Tanda Meta + guías + warm-up (2026-07-08, ✅ en prod)

- [x] **Guías del cliente (HTML)** — `docs/guias/guia-uso.md` (uso, todo el equipo) y `docs/guias/guia-meta.md` (Meta/Facebook, solo admin/soporte). Se compilan con `php artisan guias:build` → `public/guia/*.html`. Botón libro (uso) para todos + botón `pi-facebook` (Meta) gateado a admin en `AppLayout.vue`. **Retirado `docs/guia-operador.md`**.
- [x] **Códigos de error Meta al día (doc oficial v25)** — `131050` (baja desde la app → opt-out cross-channel), `131049` (tope POR USUARIO: ya no pausa el número + hold de 24h al contacto, columna `contacts.wa_marketing_hold_until`), `131064` (pausa el número). Docs corregidos `467→190`, `470→132001`. Referencia oficial Meta v25 guardada en `.claude/rules/contexto-meta-whatsapp.md`.
- [x] **Alta de números WhatsApp en el panel** — Configuración → **Números de WhatsApp** (solo superadmin): `PhoneNumberController` (index/store/update) + `PhoneNumberVerificationController` (verify). Verifica contra Meta al guardar (mensajes de error amigables en español), activar/desactivar, reemplazar número quemado. **No pide token** (reusa el de la cuenta/WABA) ni **límite** (lo pone Meta); IDs validados numéricos front+back. Nunca expone token/IDs internos. Servicio `PhoneNumberVerifier`.
- [x] **Fix global `Accept: application/json`** en `api.js` — los errores de validación ya no salían como "Error del servidor"; ahora se muestran los mensajes 422. + fix autocomplete del token (`one-time-code`).

### Documentación al usuario (siguiente etapa)
- [x] Definir formato de entrega de guías al cliente — HTML autogenerado (`guias:build`), servido en `/guia/uso.html` y `/guia/meta.html`, accesible desde el panel.
- [x] QA / capacitación / video — reemplazados por las guías HTML. Entrega vía intermediario, sin sesión ni video (descartado).

### Backlog abierto (2026-07-08)
- [x] **SMS inbound reconcile** (2026-07-08, validado en prod 2026-07-09) — `sms:reconcile-received` (ver arriba). Ruta del gateway **confirmada**: `POST {url}/messages/inbox/export` (no `/inbox/export`). Probado en prod: recuperó un entrante y el dedup por `gateway_message_id` evitó duplicarlo.
- [x] **Confirmar pool SMS multi-celular** (2026-07-08) — VERIFICADO en código: el envío SMS (`CampaignController::storeSms`/`execute` → `SendSmsMessage::dispatch(contact, campaign, body)`) NO pasa `phone_number_id` ni device; `SmsGatewayClient::send()` manda solo `{message, phoneNumbers:[to]}`. El pool de chips lo resuelve el gateway (round-robin). **Escalar a N teléfonos = solo darlos de alta en el gateway capcom6, cero cambios en el panel.** Único cuidado: dejar `SMS_GATEWAY_DEVICE_ID` **vacío** para que `sms:reconcile-received` (`messages/inbox/export`) re-exporte de TODOS los devices. Los ids de mensaje del gateway son únicos por device, así que el dedup por `gateway_message_id` aguanta el pool.
- [x] **Warm-down por calidad suave** (2026-07-08) — `wa:warmup-numbers` ahora lee `quality_rating` de Meta por número: **RED** recula (`backOffDailyLimit`, sin pausar), **YELLOW** hold (ni sube ni baja), **GREEN/UNKNOWN** warm-up normal. Tolerante a fallos de Meta (UNKNOWN). Cierra el hueco de una degradación que no dispara error de envío.

### Fixes de revisión (2026-07-10)
- [x] **Baja no ocupa agente** — `WebhookController` procesa la intención ANTES de asignar: si el inbound es baja (opt-out), NO auto-asigna y **suelta** la asignación existente (`AssignmentService::unassign` borra las filas del contacto; `user_id` no es nullable). Cualquier otra respuesta sigue auto-asignando en el 1er inbound.
- [x] **Motivo del fallo SMS visible** — antes `sms:failed`/reconcile solo logueaban el `reason`; ahora lo persisten en `message_log.error_message` (o texto genérico si no hay detalle). El detalle de campaña muestra el motivo por canal: SMS = motivo real (`smsErrorText`), WhatsApp = "error Meta" (ya no se mezcla).
- [x] **Visibilidad de excluidos por baja** — el endpoint `campaigns/{id}/logs` devuelve `stats.excluded_optout` (contactos del segmento con `status=opted_out`, que se filtran antes de despachar y no generaban fila). El detalle muestra un aviso informativo. Solo lectura: NO toca la cola ni la lógica de envío.
- [x] **Aclarado (no bug):** baja (STOP / DAR DE BAJA) es **cross-channel por ley** - bloquea WhatsApp Y SMS. Solo dedup/cooldown/pospuesto son por canal.
- [x] **Contadores vs estado real (falla post-envío)** — antes `sent_count`/`failed_count` se fijaban al despachar y NO se corregían si el mensaje fallaba después por webhook/reconcile (un SMS `sent`→`failed` quedaba como Enviado 1 / Fallidos 0 → columna "-"). Fix: `MessageLog::markDeliveryFailed()` (atómico, `sent_count--`+`failed_count++` solo si venía contado como enviado; idempotente ante webhooks repetidos; `GREATEST(...,0)`). Usado en WhatsApp (`WebhookController` fallo de entrega), SMS webhook (`handleFailed`) y `sms:reconcile-status`. Ahora lista, tiles del detalle y barra cuadran. (Las filas viejas previas al fix siguen sin motivo/contador retroactivo.)

### Auditoría y endurecimiento pre-producción (2026-07-11, ✅ en main; falta `./deploy.sh` para prod)
- [x] **Horario forzado DENTRO del job**, no solo al despachar. Nuevo `App\Services\WhatsApp\SendWindow` (fuente única: `isOpen()`/`nextOpening()`, L-V 9-22h `America/Mexico_City`, respeta `schedule_bypass`). Lo usan `execute()`, `retryPending()` (ahora con puerta 422) y el guardia en `SendWhatsAppMessage` (reencola fuera de ventana). Motivo: en prod el worker corre 24/7 por Supervisor → la ventana del `Kernel` (schedule:run) era vestigial y una campaña grande cruzaba las 22:00.
- [x] **Fix bug cooldown** — miraba solo `status='sent'`; al confirmar entrega el webhook lo pasaba a `delivered`/`read` y el cooldown quedaba ciego → reenviaba tras el dedup de 1 día. Ahora cuenta `['sent','delivered','read']` (igual que el dedup) en `SendWhatsAppMessage`, `SendSmsMessage` y `ContactController` (lista + check individual). El badge "Enfriamiento" ahora es fiel.
- [x] **R4** — placeholders de email `prestamas.mx` → `prestamaz.mx` (con Z). Copy del error de horario ahora dice "hora del centro de México (CDMX, GMT-6)".
- [x] **Prod endurecido** (staging con WABA de Alexis): `schedule_bypass=0`, `SMS_WEBHOOK_SECRET` activo y probado e2e (delivered + STOP), `LOG_LEVEL=warning`.
- [x] **Checklist de corte a cliente** — `docs/checklist-produccion-cliente.md` (enlazado en CLAUDE.md). Pendiente para cliente real: swap WABA/Phone ID/token de Meta, `MAIL_*` real, no correr `ContactSeeder`, cambiar passwords semilla. Abierto: `demoReset` sigue reactivando bajas de WhatsApp (decidir si esconderlo).

### Backlog abierto (2026-08-09) - CERRADO y validado en prod 2026-08-09 (v0.29.1)

> Rama `feature/template-and-limits-hardening`. **482 tests verdes** (1,291 assertions, 23 nuevos).
> Los cinco puntos nacieron del corte a la cuenta Meta del cliente ese mismo día: son los huecos
> que aparecieron al operar de verdad, no al programar. Dos extras salieron de la validación:
> el sync guardaba el `rejected_reason: "NONE"` que manda Meta cuando la plantilla **no** está
> rechazada (el panel mostraba "Rechazada: NONE" en plantillas aprobadas), y el botón de prueba
> no estaba gateado por la imagen, así que dejaba mandar un mensaje que Meta no iba a entregar.

- [x] **El límite del portafolio se muestra mal en el Dashboard.** `portfolioLimitLabel` en [`DashboardView.vue`](../resources/js/views/DashboardView.vue) hace `String(raw).replace(/\D/g,'')`, así que `TIER_2K` se convierte en `2` y la tarjeta dice "Límite de la cuenta (Meta): 2". **Solo es el texto**: el backend parsea bien (`PortfolioLimit::daily()` entiende los sufijos `K`/`M`), así que el freno de envío y la capacidad usan 2,000 real. Causa de fondo: hay dos parseos, uno en PHP y otro en JS, y solo el de PHP sabe de sufijos. Arreglo: que `phoneHealth()` devuelva también `portfolio_limit_daily` ya resuelto y que el front solo formatee (una sola fuente de verdad).
- [x] **`wa:warmup-numbers` no refresca el límite del portafolio desde Meta.** Lee el `Setting wa_portfolio_daily_limit` guardado, y el **único** punto que lo actualiza es `SettingsController::phoneHealth()`, o sea cuando alguien abre el Panel. Si Meta sube el tier y nadie entra al panel por días, el warm-up se queda topado en el límite viejo: no es peligroso (nunca rebasa a Meta) pero desperdicia capacidad. Arreglo: el comando ya inyecta `WhatsAppClient` y ya hace un GET por número para el `quality_rating` - agregar `whatsapp_business_manager_messaging_limit` a esa misma llamada y persistirlo. Cero llamadas extra, y el sistema queda autónomo aunque nadie abra el panel.

- [x] **Subir la imagen de plantilla desde el panel** (hueco detectado al conectar la cuenta del cliente). Hoy el envío de una plantilla con header de imagen usa el archivo local `storage/app/public/templates/{template_name}.jpg` ([`TemplateBuilder::resolveImageUrl`](../app/Services/WhatsApp/TemplateBuilder.php)); si no existe, cae al fallback `scontent.whatsapp.net` que Meta **no entrega** → todos los mensajes salen `failed`. Ese archivo solo se puede poner por SSH, así que **el cliente no puede crear plantillas con imagen sin nosotros** - rompe el principio "a prueba de errores del cliente". Alcance:
  - `POST /api/templates/{id}/image` (y `DELETE`) - valida tipo (jpg/png) y tamaño (Meta topa en 5MB), guarda como `{template_name}.{ext}`.
  - `resolveImageUrl()` debe buscar `.jpg` **y** `.png` (hoy solo `.jpg`).
  - `TemplatesView`: preview de la imagen actual + botón **Subir imagen** en plantillas con `header_type = IMAGE`, y badge **"Falta imagen"** cuando no hay archivo local (hoy no hay forma de saberlo hasta que falla un envío).
  - **Red de seguridad:** bloquear el uso de esa plantilla en campañas mientras falte la imagen, con mensaje claro. Sin esto el fallo sigue siendo silencioso.
  - Tests + actualizar `docs/guias/guia-uso.md` (+ `guias:build`).
- [x] **Envío de prueba de plantilla: guard de baja + helper que explique qué implica.** Prioridad baja, es completitud + pedagogía. `TemplateController::sendTest` va directo a Meta sin pasar por el job: no valida opt-out, dedup, cooldown ni ventana. En la práctica la UI ya protege (el desplegable carga `contacts({status:'active'})`, así que un contacto con baja **no aparece**), pero el backend no verifica nada y la regla del proyecto exige opt-out comprobado antes de cualquier envío. Alcance:
  - **Guard backend:** rechazar con 422 si el contacto está `opted_out` o en blacklist (cierra el acceso directo al endpoint y la ventana en que la lista del front quedó vieja).
  - **`HelpPopover` en el diálogo de prueba** explicando qué pasa al enviar una plantilla: (1) es un mensaje **real**, idéntico al de una campaña; (2) **consume cupo** del límite diario del número; (3) deja al contacto en **cooldown 7 días**, o sea no podrá recibir campañas en ese periodo; (4) cuenta como conversación de marketing y **aparece en la factura de Meta**; (5) si el contacto responde dentro de 24h, esa conversación de servicio no cuenta al límite. **Sin monto en pesos ni dólares**: el precio lo fija Meta y cambia - decir que se factura, no cuánto.
  - Lo demás (dedup, cooldown, horario) se salta a propósito: es una prueba.
  - Motivo real del punto: en el corte del cliente (2026-08-09) dos pruebas dejaron dos números congelados 7 días sin que nada lo avisara.
- [x] **`wa:sync-templates` debe limpiar las plantillas que ya no están en la WABA.** Hoy solo hace `updateOrCreate`, nunca borra ([`SyncWhatsAppTemplates`](../app/Console/Commands/SyncWhatsAppTemplates.php)). Al conectar la cuenta del cliente (2026-08-09) el panel siguió mostrando las plantillas de la WABA anterior y hubo que entrar por SSH a correr `WaTemplate::query()->delete()` - el cliente no puede hacer eso. Alcance: tras sincronizar, borrar (o marcar como obsoletas) las filas cuyo `name`+`language_code` no vino en la respuesta de Meta para el `WA_WABA_ID` actual. Es seguro: `campaigns.template_name` es string, no FK. Considerar avisar en la UI cuántas se quitaron.
- [x] **Guía: cómo crear plantillas, con sección de plantillas con imagen.** `guia-meta.md` (sección 3) explica cómo crearlas en Meta pero **no** menciona que una plantilla con imagen necesita además subir el archivo en el panel. Documentar el flujo completo de punta a punta: crear en Meta → esperar aprobación → sincronizar → subir imagen en el panel → recién ahí usarla en campañas. Depende del punto anterior.


---

### Ampliación aprobada por el cliente (2026-09-01 → 2026-09-03) - rama `feature/tags-fase-1`

> Cotización de 9 partidas aprobada el 2026-09-01. Aquí van las **7 que pidió arrancar**
> (falta PL1, plantillas SMS con variables desde API). **786 tests verdes** (eran 565),
> v0.31.0 → v0.39.0.
>
> ✅ **DESPLEGADO Y VALIDADO EN PRODUCCIÓN el 2026-09-05.** Los siete módulos se probaron a
> mano uno por uno contra datos reales, no solo con la suite. Lo que destapó esa validación
> está abajo: un cambio de diseño en C1 y tres bugs que ningún test cubría porque nadie había
> pensado en ellos.

**El deploy necesita tres cosas fuera de lo normal:**
1. `composer install` - dependencia nueva (`barryvdh/laravel-dompdf`).
2. `migrate` - autoría en `conversation_assignments`.
3. Verificar el cron del scheduler (`schedule:run`), o `contactos:sincronizar` nunca corre.

- [x] **C3 - Borrar etiqueta con confirmación y conteo previo.** `GET /api/tags/{id}/usage`
  devuelve cuántos contactos y campañas la usan. **Bloqueo duro (422)** si una campaña en
  `draft` o `paused` la usa: `campaigns.tag_id` es `nullOnDelete` y el despacho solo segmenta
  `if ($campaign->tag_id)`, así que borrarla dejaba una campaña de 500 apuntando a TODA la
  base. El `DELETE` revalida el bloqueo, no confía en el conteo previo. Antes borraba en seco,
  sin preguntar nada. `App\Services\Tags\TagDeletionGuard`.
- [x] **C2 - Columna de etiqueta en importación y exportación.** El importador cambia de
  propósito: su trabajo principal pasa a ser **etiquetar**, no dar de alta (las altas vienen
  de C1). Por eso una fila cuyo teléfono ya existe **recibe la etiqueta igual** en vez de
  descartarse como duplicado (opción A, decisión del cliente). Encabezados `etiqueta` /
  `etiquetas` / `tag` / `tags`, varias por celda separadas por coma, la llave es el **slug**
  (`VIP` y `vip` son una). Nada fila por fila: etiquetas resueltas de una, teléfonos existentes
  por lotes de 1,000, `insertOrIgnore` masivo. `upload()` salió del controller a
  `App\Services\Contacts\ContactImporter`. El Excel de exportación gana columna
  **Etiquetas** (mismo formato que lee el importador) y pasa de `get()` a `chunk(1000)`.
- [x] **T1 - Catálogo de etiquetas en vista propia** (`/tags`). Nombre, identificador,
  contactos (es botón: lleva a Contactos filtrado, `?tag=ID` en la URL), campañas que la usan,
  creada. Resumen con etiquetas **sin usar** para limpiar. Renombrar necesitó `PUT /api/tags/{id}`,
  que no existía; **el slug NO se regenera** al renombrar, porque es la llave con la que
  `TagResolver` reconoce una etiqueta y cambiarla haría que un Excel viejo creara una duplicada.
  Visible para **admin y operator** (no solo admin: el operador ya crea y borra etiquetas desde
  Contactos y las rutas son `role:admin,operator`).
- [x] **P2 - Reasignar conversaciones + historial con autoría.** `conversation_assignments`
  pasa a ser un libro que solo crece: `assigned_by_id` (null = lo hizo el sistema), `action`
  (`auto`/`manual`/`claim`/`reassign`/`release`) y `user_id` **nullable** para registrar la
  liberación como una fila más. Antes soltar una conversación **BORRABA** las filas y con ellas
  el historial, justo en el cambio de turno. Modal con todos los movimientos, `POST .../release`
  y `GET .../history` (admin/operator). Reasignar al mismo agente se rechaza (422) para no
  ensuciar el historial. **Lleva migración.**
- [x] **S1 - API pública de contactados por fecha.** `GET /api/contacted?date=YYYY-MM-DD` o
  `from`/`to`, con **`X-API-Key`** (`ApiKeyMiddleware`, que existía sin usarse) porque la consume
  otro servidor, no el panel. Devuelve nombre y teléfono, **una fila por contacto**. "Contactado"
  = el mensaje salió (`sent`/`delivered`/`read`), los dos canales - mismo criterio que el
  enfriamiento, para que el número cuadre con lo que ve el operador. Las fechas viajan como
  **texto** `Y-m-d` hasta el servicio: con objetos Carbon el día se corría (`createFromFormat`
  conserva la hora actual y al pasar de UTC a CST una petición de las 02:00 caía en el día
  anterior). Documentada para el cliente en [`docs/api-contactados.md`](api-contactados.md).
- [x] **P1 - Reporte de conversaciones por agente** (`/reports/agents`), operador hacia arriba.
  **Dos columnas** porque "cuántas conversaciones tiene un agente" son dos números distintos:
  *Recibidas en el periodo* (responde al filtro de fecha) y *Abiertas ahora* (foto del momento,
  ajena al filtro). Un agente puede haber recibido 12 hoy y tener 40 abiertas por arrastre. Con
  una sola columna el reporte mentiría en la mitad de los casos. Filtros por rango y por agente,
  descarga **Excel y PDF**. Los que están en cero también salen. Agrega `barryvdh/laravel-dompdf`
  (PHP puro, sin Chromium en el VPS); la plantilla Blade usa tabla y estilos básicos a propósito.
- [x] **C1 - Cron diario que da de alta contactos del API del cliente.** Solo agrega: quien ya
  existe conserva su nombre del panel, quien pidió su baja **nunca revive** y un borrado se salta
  (`phone` es UNIQUE). Scheduler **04:00 CST**, antes del warm-up (05:00) y de la ventana (09:00).
  Comandos `contactos:probar-api` (diagnóstico, no escribe, nunca imprime la contraseña) y
  `contactos:sincronizar --dry-run`. Ver [`docs/sincronizacion-contactos.md`](sincronizacion-contactos.md).
  **Falta**: poner el `.env` en el VPS y que el cliente decida qué estados de cartera dar de alta.

#### Lo que destapó validar en producción (2026-09-05)

> Cada módulo se probó a mano contra datos reales. Casi todo salió a la primera; lo que no,
> está aquí. Los tres bugs tienen algo en común: **ninguno rompía nada visiblemente**, por eso
> ni la suite ni el desarrollo los habían encontrado.

- [x] **C1 cambia de diseño: el estado de cartera es COLUMNA, no etiqueta.** Decisión de
  Alexis al revisar el flujo. La versión de etiqueta tenía tres problemas: **se congelaba**
  (solo se etiquetaba a los contactos nuevos, así que quien entró como `BURÓ` seguía marcado
  así aunque después liquidara - y `LIQUIDADO` es justo el segmento que el cliente vende);
  las etiquetas las crea, renombra y borra el operador, y un dato que escribe un proceso
  automático no puede vivir donde alguien lo borra sin querer; y si mañana su API agrega un
  campo `tag` propio, chocaría en el mismo catálogo. Ahora es `contacts.portfolio_status`
  con índice propio, visible como **Cartera** con filtro, y **se refresca en cada corrida** -
  lo único que la sincronización actualiza de un contacto existente. Las campañas siguen
  segmentando por etiqueta; el puente es **"Etiquetar todo lo filtrado"**.
- [x] **`tag_id` significaba dos cosas en la misma petición** - "filtra los que YA tienen esta
  etiqueta" y "ponles esta etiqueta". El filtro ganaba y **no etiquetaba a nadie, sin error**.
  La etiqueta a poner viaja en `attach_tag_id`. Salió al escribir el test, no al programar.
- [x] **Crear una etiqueta validaba el nombre, no el identificador.** "QA Import Renombrada" y
  "qa import" comparten el slug `qa-import`, así que pasaban la validación y MySQL respondía
  con un `Integrity constraint violation 1062` **en crudo, en pantalla**. Ahora se valida el
  slug, el mensaje nombra la etiqueta que estorba y sale **debajo del campo**, no en un toast.
- [x] **El reporte de la sincronización no cuadraba.** En la primera corrida real: 14,872
  recibidos, 14,758 válidos, 4 inválidos - faltaban **110 filas sin explicar**. Eran teléfonos
  que su API manda más de una vez. Ya tienen su renglón, y el comando verifica que
  `inválidos + repetidos + excluidos + válidos = recibidos`, avisando si alguna vez deja de
  cumplirse. De paso: los repetidos **contaban doble en el desglose por estado**, que es el
  número con el que el cliente decide a quién excluir.
- [x] **Copy que le pedía al operador algo imposible.** El bloqueo al borrar una etiqueta decía
  "cámbiale el segmento a esa campaña". Las campañas **no se pueden editar** - no existe esa
  pantalla - y "segmento" no aparece en ninguna parte de la UI (el campo se llama
  **Destinatarios**). Ahora ofrece las dos salidas que el panel sí permite. La misma jerga se
  limpió del detalle de campaña, el catálogo, las plantillas y la guía.
- [x] **Un cuarto estado de cartera que no conocíamos: `ACTIVO`**, 1,704 de 14,872 (el segundo
  grupo más grande). La muestra inicial solo traía tres registros. No hizo falta tocar código:
  nada filtra por nombre de estado y el desplegable se arma con lo que hay en la base.
- [x] **El texto del diagnóstico mentía.** `contactos:probar-api` seguía diciendo que cada
  contacto nuevo se etiqueta con su estado - comportamiento de la versión reemplazada.

#### Bugs del cliente, cerrados

- [x] **Lag al escribir en Conversaciones** (v0.38.2). Eran dos cosas sumadas: `newMessage`
  vivía en el mismo componente que dibuja la lista lateral (938 filas en prod), así que cada
  tecla la rediseñaba entera; y cada fila llamaba a seis funciones desde la plantilla, la peor
  `formatTime`, que armaba un `Intl.DateTimeFormat` **por fila**. Ahora el escritor y la fila
  son componentes propios (`ConversationComposer`, `ConversationListItem`) y el formateador es
  uno para toda la lista. Extra: la cajita ya no se limpia sola al enviar - la limpia el padre
  solo si el mensaje salió, así que un fallo de red deja de comerse lo escrito.

#### Vigilancia del cron (2026-09-05, no cotizado)

- [x] **El panel avisa si el programador de tareas se detiene** (v0.39.0). Una sola línea de
  crontab mueve todo lo automático: alta de contactos, warm-up, marcado de inalcanzables y las
  reconciliaciones de SMS. Si se rompe, **nada avisa** y el sistema se desafina en silencio
  durante días. Como un cron muerto no puede reportarse, la detección va al revés: el scheduler
  deja un latido cada minuto y el **panel** nota que está frío. Pasados 15 minutos sale una
  barra roja para admin y operador; `deploy.sh` revisa lo mismo al final. El texto dice que las
  campañas **sí siguen enviándose** (el worker corre bajo Supervisor, aparte del cron): decir
  que todo se paró sería falso y haría que dejaran de trabajar sin razón.
  `App\Services\System\SchedulerHeartbeat`. **Sin correo** (el panel nunca ha tenido SMTP);
  queda anotado el interruptor de hombre muerto externo para cubrir el servidor apagado.

#### Extras que salieron de la misma tanda (no cotizados)

- [x] **Pegado masivo de números en el buscador de Contactos.** El cliente pega 500 números de
  Excel. Va por `POST /api/contacts/search` porque 500 números son ~6.5 KB de query string y
  2,000 pasan de 25 KB: **nginx corta con 414** antes de que Laravel se entere. `index()` (GET) y
  `search()` (POST) comparten `respondWithPage()`. Reporta cuáles **no están dados de alta** con
  botón para copiarlos. El espacio es ambiguo (separa números pero Excel también lo mete dentro
  de uno), así que el parser corre dos veces y gana el corte que rescata más números válidos.
- [x] **Selector de registros por página** (`TablePaginator.vue`) en Contactos, Campañas,
  Respuestas SMS y mensajes del Panel: 10/20/50/100/250/500/**Todos**. "Todos" tiene **tope duro
  de 5,000** con aviso visible - con 200k contactos el JSON pesa decenas de MB y el navegador se
  cuelga. Un `per_page` fuera del catálogo cae al default. `App\Support\PageSize`.
- [x] **Filtro de entregabilidad por canal y acumulativo.** Multiselección: "Enfriamiento -
  WhatsApp" no es "Enfriamiento - SMS". Respeta la precedencia de las etiquetas de la fila.
  Los `orWhere` van envueltos en su propio `where()`: sin ese paréntesis el primer OR se escapa
  y **anula** los filtros de estado, tag y lista pegada (hay test). Índice nuevo
  `idx_logs_to_channel_status_sent` sobre `message_log (to_number, channel, status, sent_at)`:
  ninguno de los previos incluía `channel`.
- [x] **Estado de cartera en C1: columna, no etiqueta.** El API trae un campo `Estado`
  (`LIQUIDADO`, `BURÓ`, `BAJA`) que no esperábamos. **Su `BAJA` NO es nuestra Baja**: la suya
  significa que dejó de ser su cliente, la nuestra es opt-out irreversible por ley. Un contacto
  en `BAJA` entra como **Activo**. No se excluye a nadie por default (descartar en silencio sería
  peor y la decisión es del cliente); `--dry-run` da el desglose para llevarle números al cliente.
  `SYNC_STATUS_EXCLUDE` / `SYNC_STATUS_INCLUDE` cuando decida.
  - **Primera versión: etiqueta por estado. Se descartó** (decisión de Alexis, 2026-09-03) por
    tres razones. (1) **Se congelaba**: la sincronización solo etiquetaba a los contactos nuevos,
    así que quien entró como `BURÓ` seguía marcado `BURÓ` aunque después liquidara - y
    `LIQUIDADO` es justo el segmento que le importa al cliente. (2) Las etiquetas las crea,
    renombra y borra el operador: un dato que escribe un proceso automático no puede vivir donde
    alguien lo borra sin querer. (3) Si mañana su API agrega un campo `tag` propio, chocaría con
    los estados en el mismo catálogo.
  - **Ahora:** columna `contacts.portfolio_status` (índice propio), visible como **Cartera** en
    Contactos, con filtro. **Se refresca en cada corrida** - es lo único que la sincronización
    actualiza de un contacto existente; el nombre y la baja siguen intocables. Apagable con
    `SYNC_REFRESH_STATUS=false` si el cliente prefiere congelarlo. El comando reporta
    "Estado actualizado" / "Cambiarían de estado".
  - **Las campañas NO segmentan por cartera** (queda fuera a propósito): siguen segmentando por
    etiqueta. El puente es **"Etiquetar todo lo filtrado"**: el operador filtra por `LIQUIDADO`,
    le pone `Renovación septiembre` a todos y crea la campaña con esa etiqueta. Así la columna
    dice cómo está **hoy** y la etiqueta registra **a quién se le mandó**.
- [x] **Etiquetado masivo sobre el filtro + rendimiento del existente.** `bulkAttach` hacía
  `syncWithoutDetaching` **una consulta por contacto**: con 5,000 seleccionados eran 5,000
  consultas y la petición moría por timeout. Ahora es un `insertOrIgnore` (y el detach un solo
  `delete`). Nuevo `POST /contacts/tags/bulk-attach-filtered`, que recorre con `chunkById` todo
  lo que cumple el filtro - las casillas solo alcanzan lo cargado en pantalla (tope 5,000), y con
  40,000 `LIQUIDADO` el operador tendría que hacerlo en ocho tandas. `bulk-preview` devuelve el
  total para confirmar antes. Los filtros salieron del controller a `App\Services\Contacts\ContactFilters`,
  compartido por el listado y el etiquetado: si cada uno armara su query, el operador etiquetaría
  un conjunto distinto del que tiene enfrente.
  - **Bug que destapó la prueba:** `tag_id` significaba dos cosas en la misma petición - "filtra
    los que YA tienen esta etiqueta" y "ponles esta etiqueta". El filtro ganaba y no etiquetaba a
    nadie, **sin error**. Por eso la etiqueta a poner viaja en `attach_tag_id`. De paso queda
    habilitado el caso útil: "a los que tienen VIP, ponles Renovación". Tiene test.

### Entrega del API al cliente y visibilidad del cron (2026-09-08)

- [x] **El API de contactados tenía TRES formatos de error.** Lo encontró el cliente corriendo
  la colección de Postman, no nosotros: una fecha mal escrita salía con la forma por default de
  Laravel (`{message, errors:{...}}`, en inglés, sin `code`), el 401 salía como
  `{"error":"Unauthorized"}`, y solo el resto usaba el contrato documentado. Su programador se
  topaba con el inglés al primer intento. Ahora todo pasa por
  `App\Http\Requests\ContactedRequest` (con `failedValidation` devolviendo `INVALID_PARAMS`)
  y `ApiKeyMiddleware` responde igual con `UNAUTHORIZED`. **La doc fuente llegó a documentar la
  inconsistencia como si fuera normal**; ese párrafo ahora dice la regla.
- [x] **Una sola guía para el cliente.** Se había creado un LEEME aparte que repetía casi todo
  lo de `api-contactados.md` - tabla de errores, qué cuenta como contactado, paginado, límite,
  fechas en hora de México. Dos documentos diciendo lo mismo se desincronizan. Se fusionaron:
  queda `docs/api-contactados.md` con una sección de "Comprobar que funciona" arriba, más
  `docs/api-contactados.postman_collection.json`.
- [x] **La colección de Postman verifica, no solo consulta.** Comprueba que ningún contacto se
  repita en una página, que la página 2 no repita los de la 1, y que cada error traiga su
  `code`. Las peticiones de error llevan **fechas fijas**: al usar variables, una sin resolver
  mandaba el placeholder literal y todas fallaban por formato, tapando el error que cada una
  quería demostrar - que fue justo lo que le pasó al cliente.
- [x] **La doc se publica en `/doc/api-contactados.html`.** Se arma con `guias:build` junto a
  las dos guías del panel y su HTML viaja al repo, así que llega a producción con un deploy
  normal y no se puede desincronizar de su Markdown. El resto de `public/doc/` sigue
  gitignoreado: ahí caen los renders de un solo uso. `guias:build` acepta un archivo suelto
  para eso. **Ojo:** publica dentro de `public/`, así que **no** correrlo en el servidor con
  documentos internos - `sincronizacion-contactos.md` trae IPs y rutas del cliente.
- [x] **Queda registro de cada corrida de la sincronización.** El cron corre a las 4 AM y nadie
  ve esa consola: solo `recibidos` y `nuevos` llegaban al log, y en producción el `LOG_LEVEL`
  puede estar en `warning`, con lo que ese `Log::info` no se escribe nunca. El resumen completo
  se guarda ahora en el `Setting contact_sync_last_run` y se consulta con
  **`contactos:ultima-corrida`** (y `--cartera` para el desglose actual). Los fallos también se
  guardan con su motivo. El `--dry-run` **no** deja registro: un ensayo no es una corrida.

### Anotado para cuando crezca (sin urgencia)

- [ ] **Paginar la lista de Conversaciones.** `ConversationController::index` hace `->get()`:
  trae **todos** los contactos que tengan al menos una conversación, sin tope. Hoy son 938 y
  crece con cada persona que responde una campaña. No es lo que causaba el lag al escribir
  (eso se cerró en v0.38.2 sacando el escritor y la fila a componentes propios), pero es el
  siguiente techo del módulo.

  **Qué pasa hoy:** cada refresco son ~190 KB de JSON, y el frontend **refresca la lista
  completa cada vez que entra un mensaje**. En hora pico eso es varias veces por minuto. Con
  200,000 contactos en la base, si un 5% llega a responder alguna vez, son 10,000 filas por
  petición: el navegador se cuelga y el servidor arma un JSON de megas.

  **El obstáculo real - por qué no es cambiar `get()` por `paginate()`:** el orden se hace
  **en PHP**, no en SQL (`->get()->sortByDesc('last_message_at')`). Y se hace ahí porque la
  llave de orden no es una columna de `contacts`: sale de una relación cargada aparte
  (`latestConversation.created_at`). Paginar en SQL exige ordenar en SQL, y ordenar en SQL
  exige que esa fecha sea alcanzable desde la consulta. Si se pagina sin resolver eso, el
  servidor devolvería "los primeros 50 por id" y **luego** los ordenaría - o sea, la página 1
  no traería las conversaciones más recientes. Sería peor que no paginar, y en silencio.

  **Lo que hay que hacer, en orden:**
  1. **Que la fecha del último mensaje sea consultable.** Lo más limpio es una columna
     `contacts.last_message_at` denormalizada, que se actualiza al guardar un mensaje
     (entrante y saliente), con índice. La alternativa sin columna es una subconsulta o un
     join contra `conversations`, que sobre 200k filas hay que medir antes de elegir.
  2. **Mover el orden a SQL** y recién entonces paginar.
  3. **Buscador del lado del servidor.** Sin él, paginar *empeora* la vida del operador: hoy
     encuentra a cualquiera con Ctrl+F porque están todos cargados. Con páginas, el que no
     esté en la primera desaparece. El buscador no es un extra, es parte del mismo cambio.
  4. **Que el tiempo real no rompa la paginación.** Hoy un mensaje entrante dispara un
     refetch completo; con páginas, eso devolvería al operador a la página 1 mientras lee.
     Hay que refrescar solo la página visible, o mejor, actualizar la fila que cambió sin
     volver a pedir la lista.

  **Cuándo:** no es urgente, es preventivo. Vale la pena antes de que el cliente pase de unas
  pocas miles de conversaciones - y con la sincronización del API metiendo 11,719 contactos,
  ese momento se acerca. Buen momento para medirlo: cuando la lista pase de ~3,000 filas.

  **Ojo con el filtro del agente:** un agente solo ve las suyas, y ese filtro usa
  `MAX(id)` sobre `conversation_assignments` en un `whereHas`. Al paginar hay que confirmar
  que ese subquery siga siendo eficiente, o el paginado le va a salir lento justo a quien más
  usa la pantalla.
- [ ] **Interruptor de hombre muerto externo** (healthchecks.io o similar). La barra del panel
  cubre "cron roto, servidor vivo", que es el caso común. No cubre el servidor apagado: ahí el
  panel tampoco responde y nadie se entera. Son ~10 líneas y una cuenta gratis.
- [ ] **PL1** - plantillas SMS con variables desde el API. Novena partida de la cotización,
  nunca se arrancó. Fuera de esta fase a propósito.

### Resuelto: no cuadraban los contactos que cargó el API (2026-09-08 / 2026-09-09)

- [x] **Causa raíz: un permiso de archivo, no el API ni el código de sincronización.**
  El panel tenía 6,179 contactos y ninguno con `source = 'api'`: la sincronización **nunca
  llegó a ejecutarse**. Las cuatro hipótesis que habíamos anotado eran todas falsas.
  **La cadena real:** `deploy.sh` y los comandos manuales corren `artisan` como `adminsender`
  con `umask 0022`, así que los archivos nacen `0644` (sin escritura para el grupo). Entre el
  5 y el 6 de septiembre uno de esos comandos creó el archivo de candado de
  `withoutOverlapping()` del job (`storage/framework/cache/data/19/08/190878355cf3...`) a
  nombre de `adminsender`. El cron corre como `www-data`, que necesita **abrirlo en escritura**
  para evaluar el mutex; sin el bit de grupo tira `Permission denied`. La excepción no está
  capturada en `ScheduleRunCommand`, así que **muere el `schedule:run` completo de ese minuto**
  y el comando ni arranca. Se repitió idéntico el 6, 7 y 8 de septiembre a las 10:00:01 UTC
  (4:00:01 AM CST), y el deploy del 8-sep a las 21:46 borró el archivo, que es por lo que ya
  no aparecía al diagnosticar.
  **Por qué el latido del cron no avisó:** solo moría ese minuto. Los otros 1,439 del día
  `schedule:run` corría bien y `SchedulerHeartbeat` seguía latiendo. El panel decía "cron vivo"
  con razón, y aun así el job llevaba tres días sin ejecutarse.
  **Arreglo aplicado en el VPS:** `chown -R www-data:www-data storage bootstrap/cache`,
  directorios a `2775` y archivos a `0664`. `adminsender` ya estaba en el grupo `www-data`.
- [x] **Descartado en el camino, con evidencia.** (1) Lote parcial: habría dejado un múltiplo
  de 500, dejó **cero**. (2) `SYNC_STATUS_EXCLUDE`: no existe en el `.env`, y el dry-run reporta
  `Excluidos por su estado = 0`. (3) API con menos registros: responde 200 con 14,940. (4)
  Timeout: nunca hubo corrida que pudiera expirar. Aparte se encontró y ya estaba corregido un
  `login rechazado {"status":401}` del 5-sep (contraseña mal en el `.env`).
- [x] **Trampa de diagnóstico que costó una vuelta.** Los logs están rotados por día
  (`storage/logs/laravel-YYYY-MM-DD.log`); el `storage/logs/laravel.log` monolítico dejó de
  escribirse el **3 de julio**. Grepear ese archivo devuelve vacío y parece que no hay nada.
  Buscar siempre en `laravel-2026-*.log`. Segunda trampa: `find ... 2>/dev/null` corriendo como
  `adminsender` se traga los "Permission denied" del propio `find` y **oculta** los directorios
  de `www-data`; para auditar permisos hay que usar `sudo find`.
- [x] **Sincronizado.** Total en el panel: **14,908 contactos** (8,729 altas nuevas, 6,097 ya
  existían y recibieron su estado de cartera, 4 teléfonos ilegibles, 110 repetidos en la
  respuesta del API). Cartera: LIQUIDADO ~12,692, ACTIVO ~1,707, BURÓ ~410, BAJA ~17.
  Se decidió **no** usar `SYNC_STATUS_EXCLUDE`: BURÓ entra a la base y se filtra al armar la
  campaña, por la columna Cartera. Excluir al insertar perdería el dato para siempre.
  Los 8,729 quedaron con la etiqueta `SYNC_TAG="API cliente"` (con comillas: sin ellas el
  `.env` corta el valor en el espacio).
- [x] **Descubierto de paso:** entre el 7 y el 8 de septiembre alguien subió ~3,000 contactos
  por Excel que el API ya traía. Por eso los "ya existían" pasaron de 3,039 (5-sep) a 6,097
  (9-sep). Se estaba capturando a mano lo que el cron debía traer solo.

**Pendientes que dejó este caso:**

- [x] **`umask 0002` en `deploy.sh`** (hecho). Con el comentario que explica el caso, para que
  nadie lo borre pensando que sobra. Falta lo del servidor: agregarlo también al `~/.bashrc` de
  `adminsender`, que cubre los comandos que Alexis corre a mano fuera del deploy.
- [x] **RESUELTO 2026-10-29.** `contacts` ya tiene `created_by` y `deleted_by` (FK a `users`,
  `nullOnDelete`: si el usuario se borra, el contacto se queda y lo que se pierde es el
  nombre). Se llena en alta manual, importacion de Excel y borrado; nulo significa que lo
  puso el cron del API, no una persona. Columna "Dado de alta por" en el Excel de Contactos.
  Lo que NO se hizo: tabla de actividad con cada cambio.

  *Lo que decia cuando estaba abierto:* `contacts` no tiene `created_by` ni hay
  tabla de actividad, y `POST /api/contacts/upload` no registra al usuario. Hoy es imposible
  saber quién subió los Excel del 7 y 8 de septiembre: solo se reconstruye por `created_at`,
  el log de nginx (IP) y `personal_access_tokens.last_used_at`. Propuesto: tabla `activity_log`
  propia (quién, acción, cuándo, cuántos, IP) cubriendo importar contactos, dar de baja, borrar,
  etiquetar en bloque, cambiar configuración y actualizar el token de Meta.
- [ ] **Reporte de rechazados para el cliente.** El comando solo enseña 5 ejemplos y no guarda
  nada. Propuesto: `contactos:sincronizar --rechazados=archivo.csv` con el listado completo y el
  motivo de cada fila, para mandárselo al cliente y que corrija su captura. Las 4 filas de hoy:
  un celular vacío, un número de EEUU (`0014084142727` - Meta ni entrega marketing a +1), uno de
  9 dígitos y uno de 11.
- [ ] **La etiqueta "API cliente" es borrable y no se repone.** `etiquetar()` solo corre sobre
  los recién insertados, y la FK de `contact_tag` es `cascadeOnDelete`. Si el operador borra la
  etiqueta desde la pantalla de Etiquetas, los 8,729 la pierden y ninguna corrida futura se la
  devuelve. Lo indestructible es `contacts.source = 'api'`, pero **no está expuesto en la UI**.
  Propuesto: filtro "Origen" (API / Excel / Manual) en Contactos, y que la etiqueta quede como
  adorno. Encaja con el principio de que el cliente no pueda romper algo sin querer.
- [x] **RESUELTO 2026-10-29.** Tabla `contact_sync_runs`, una fila por corrida, y
  `contactos:ultima-corrida --historial` las muestra. El Setting se queda por compatibilidad.
  Contesta la pregunta que importa cuando algo va mal: **desde cuando** viene fallando.

  *Lo que decia cuando estaba abierto:* el Setting `contact_sync_last_run` se
  sobrescribe cada noche. Si se quiere historial consultable, va tabla propia.
- [x] **RESUELTO 2026-10-29.** Las dos cargas en lote (Excel y cron del API) pasan por
  `App\Services\Contacts\BulkInsert`, que recorta el nombre a lo que cabe ANTES de intentar
  y, si el lote falla igual, reintenta fila por fila para que las buenas entren y las malas
  se devuelvan con su telefono.

  *Lo que decia cuando estaba abierto:* con `contacts.name` en `varchar(100)`, un
  solo nombre largo aborta el lote de 500 **y todos los siguientes**. Hoy se verificó contra el
  API real (`max=45`, ninguno pasa de 100) y por eso la corrida fue segura, pero el dato es del
  cliente y puede cambiar sin avisar. Envolver el lote y reportar la fila que falla.

### Bugs reportados por el cliente operando (2026-09-03)

- [x] **"Los mensajes nuevos no suben al inicio de la lista"** - **RESUELTO** (confirmado por
  Alexis el 2026-10-29). Era lo que se sospechaba, no hizo falta tocar el reordenamiento. (reporte del
  cliente, **sin confirmar**). El código sí está hecho para reordenar: el backend ordena por `last_message_at`
  y el frontend hace refetch completo al recibir un inbound. En los screenshots la lista se ve
  **bien ordenada**. Falta que el cliente sea más específico; Alexis lo cree tema visual. Antes de
  tocar código, comprobar: (1) ¿le aparece el toast "Nueva respuesta"? Si no, el tiempo real no
  está llegando (Soketi) y la lista se queda congelada como cargó; (2) con 938 filas, si el
  operador está scrolleado abajo puede no ver que la fila brincó arriba.

---

## Proyecto aceptado 2026-10-27: archivos en conversaciones + OTP

El cliente aprobo la cotizacion (subtotal $25,000, $29,000 con IVA). Dos bloques: manejar
archivos en las conversaciones (fotos, documentos, notas de voz, video) y el API de OTP por
WhatsApp para su app.

Decisiones del cliente al arrancar: **OTP de 6 digitos**, su app es **Android**, y el plazo de
retencion de los archivos se define al final (no se implemento borrado por retencion todavia).

| # | Entrega | Estado | Depende de |
|---|---|---|---|
| M0 | Plantilla de Autenticacion a revision de Meta + pedir la SIM dedicada | [~] | Plantilla APROBADA; falta la SIM del cliente |
| M1 | Dejar de perder lo que ya entra (webhook + descarga a disco) | [x] | - |
| M2 | Verlas en el panel: ruta autenticada, burbuja, visor | [x] | M1 |
| M3 | Enviar imagenes desde el panel | [x] | M1 |
| M4 | PDF, notas de voz y video de salida | [x] | **Validado en prod 2026-10-30** |
| M5 | OTP: API, carril prioritario, tabla de envios | [x] | M0 aprobada |
| M6 | Numero de WhatsApp dedicado para OTP | [~] | **Panel listo**; falta la SIM del cliente |

### M1 - recepcion y guardado (2026-10-27)

- [x] `message_type` ampliado a `image`, `document`, `audio`, `video` + 6 columnas `media_*`.
- [x] El webhook reconoce los 5 tipos (el sticker entra como imagen) y guarda el pie de foto
  como cuerpo del mensaje. Antes una foto caia en el `default` del match y se guardaba como
  **texto vacio**: la burbuja salia en blanco y el archivo se perdia. En un negocio de
  prestamos eso son INE y comprobantes que nadie recibio.
- [x] La descarga va en cola (`DownloadWhatsAppMedia`), no en el webhook: Meta corta la
  conexion si tardamos y reintenta el aviso completo, asi que bajar un video de 16 MB ahi
  adentro provocaria mensajes duplicados. El `media_id` vive 30 dias, por eso reintentar sirve
  (lo que caduca en minutos es la URL, no el id).
- [x] Los archivos van al disco `local` (`storage/app`), **nunca** a `public/`: ahi los abriria
  cualquiera que adivine la URL, sin login.
- [x] El nombre en disco lo inventamos nosotros. Un contacto puede mandar un archivo llamado
  `../../.env`; el nombre que el eligio se guarda aparte, solo para mostrarlo.

**Lo que no se puede recuperar:** los archivos que llegaron antes de esto. Solo se guardo el
texto vacio, nunca el `media_id`, y del lado de Meta ya caducaron. Para dimensionarlo:
`SELECT COUNT(*) FROM conversations WHERE direction='inbound' AND body=''`.

### M0 - la plantilla de OTP (2026-10-28)

Se adelanto a proposito: es lo unico del proyecto con espera que no controlamos. Sometida ahora,
Meta revisa mientras se construye M3/M4; dejada para M5, el OTP se para en seco.

- [x] Comando `wa:crear-plantilla-otp`, **dry-run por default** (`--enviar` para someterla de
  verdad). Someter una plantilla no afecta el score de la cuenta - solo los rechazos repetidos
  del mismo contenido - pero sigue siendo una accion sobre la cuenta del cliente.
- [x] Al cliente `WhatsAppClient` se le agrego `postPath()`: `post()` esta clavado a
  `/{phone_number_id}/messages` y los endpoints de administracion cuelgan de la WABA. La regla
  de seguridad es que todo el HTTP a Meta pase por el cliente, no que haya un solo metodo.
- [x] Documentado en `guia-meta.md` seccion 3.
- [x] **Sometida y APROBADA por Meta** (verificado 2026-10-29 con `wa:sync-templates` contra la
  WABA del cliente: `prestamaz_otp_v1: approved`). Ya se puede enviar.
- [ ] **Pendiente del cliente: la SIM dedicada** para el numero de OTP (M6).

**Hallazgo que simplifica todo:** el texto de una plantilla de **Autenticacion** es **fijo**, lo
pone Meta por idioma. No se escribe. Solo se configuran tres cosas: `add_security_recommendation`
(la advertencia de no compartir), `code_expiration_minutes` (1-90) y el boton. No hay copy que
revisar, asi que el riesgo de rechazo por redaccion es casi nulo. El `BODY` **no** lleva `text`:
mandarlo hace que Meta rechace la peticion (es el error facil al copiar otra plantilla).

**Decisiones de Alexis (2026-10-27):** OTP de **6 digitos**, la app del cliente es **Android**, y
no se le van a pedir los datos tecnicos de la app.

**Consecuencia de no tener los datos de la app:** el boton es **`COPY_CODE`**, no autocompletado.
El autocompletado (`ONE_TAP`) exige `package_name` + `signature_hash` de la app Android. Con
COPY_CODE el usuario toca el boton y pega el codigo - un paso mas, pero funciona en cualquier
telefono, iPhone incluido. **Es reversible:** si algun dia dan esos datos, la plantilla se
actualiza sin empezar de cero, porque el autocompletado siempre se cae a COPY_CODE cuando la
verificacion falla.

**Trampa anotada para M5:** los minutos del pie son **solo texto**. Que diga "caduca en 10
minutos" no hace que caduque - eso lo tiene que cumplir nuestro API. Si no coinciden, el usuario
lee una cosa y le pasa otra.

**Dato para M5 al enviar el OTP - CORREGIDO contra la doc de Meta (2026-10-29):** el codigo si
va **dos veces** en el payload ("this value must appear twice in the payload", textual), pero el
segundo lugar **no** es `coupon_code`: es un componente `button` con **`sub_type: "url"`** e
`index: "0"`. Meta convierte el boton `OTP` a `URL` al aprobar la plantilla. Mandarlo como
`coupon_code` - que es lo que uno copia de una plantilla de marketing con cupon - lo rechaza.
Implementado en `App\Services\Otp\OtpTemplate`, con dos tests que fijan la forma.

### M5 - el API de OTP (2026-10-29)

**VALIDADO EN PRODUCCION el 2026-10-29**, de punta a punta: `POST /api/otp/send` -> 201 sin el
codigo en la respuesta -> el mensaje llega al WhatsApp con su boton de copiar -> `POST
/api/otp/verify` -> `verified: true`. Worker con `--queue=otp,default` aplicado en Supervisor.


- [x] `POST /api/otp/send` y `POST /api/otp/verify`, con `X-API-Key` (no Sanctum: lo llama el
  servidor de la app del cliente, y un servidor no hace login). Codigo de 6 digitos, 10 minutos
  de vida, 5 intentos, 1 minuto entre peticiones al mismo numero y tope de 10 por numero al dia.
- [x] El codigo se guarda **hasheado** y **nunca sale** en la respuesta del API ni en el panel.
  Devolverlo convertiria al OTP en un adorno: con la llave del API cualquiera entraria como
  quien quisiera, sin tocar el telefono de nadie.
- [x] Carril prioritario: el envio va en la cola `otp`, que el worker atiende **antes** que la de
  campanas. Requiere `--queue=otp,default` en Supervisor; documentado en `deploy-vps.md`.
- [x] Pantalla **Codigos** en el panel (solo admin), de lectura: estado por numero, la
  `reference` que manda la app, el motivo del fallo y el cupo que queda hoy.
- [x] `docs/api-otp.md` + `api-otp.postman_collection.json` para el programador del cliente.
- [x] Plantilla `prestamaz_otp_v1` **aprobada** en la WABA del cliente (verificado 2026-10-29).
  El nombre vive en `config/otp.php` (`OTP_TEMPLATE`); si alguna vez se crea otra, hay que
  empatar las dos o el envio devuelve 132001.

**Hallazgo que cambia el alcance de M6:** el limite diario de Meta es de
*"unique WhatsApp user phone numbers your business can deliver messages to"* fuera de la ventana
de servicio, **sin excluir categorias**, y es **por Business Portfolio**. Entonces:

1. Un OTP gasta exactamente el mismo cupo que un mensaje de publicidad.
2. **El numero dedicado de M6 NO le da capacidad propia al OTP** - comparte el mismo techo.
   Sirve para separar reputacion (si una campana quema el numero de marketing, el de OTP sigue
   limpio), no para capacidad.

Por eso el carril prioritario no podia ser solo "ponerse al frente de la fila": hace falta
**apartar cupo**. `App\Services\Otp\OtpCapacity` le resta un 10% (configurable con
`OTP_RESERVE_PERCENT`) al techo que ven las campanas, y suma las dos tablas al contar lo gastado.
Sin esa resta, un martes de campana grande deja a los usuarios de la app del cliente sin poder
entrar.

**Por que los OTP no viven en `message_log`:** el dedup y el cooldown de campanas miran esa
tabla por numero, sin filtrar por campana. Un codigo ahi adentro dejaria al usuario fuera de
toda la publicidad por 30 dias, en silencio - y los usuarios de la app son tambien los
prospectos. Tabla propia (`otp_codes`), cupo compartido.

**Decisiones de Alexis (2026-10-29):** el OTP **ignora la baja de publicidad** (la baja es de
marketing; el codigo lo pide el usuario en ese momento para entrar a su cuenta), y la reserva es
un **porcentaje** del techo, no un numero fijo, para que se ajuste sola cuando Meta suba el tier.

### Dos huecos que destapo probar en prod (2026-10-29)

**1. Las notas de voz llegaban como archivo adjunto, y ni se podian descargar.** Meta pide tres
cosas para que una grabacion se vea como nota de voz: el campo **`voice: true`** en el objeto
`audio`, el archivo en **Ogg/Opus** y **mono**. No se mandaba el campo, y la conversion rapida
(`-c:a copy`) conservaba lo que grabara el navegador - que puede ser estereo, y en Safari ni
siquiera es Ogg. Encima el Ogg que salia del copiado llegaba roto: WhatsApp lo mostraba y no lo
podia descargar. Ahora toda grabacion se recodifica a Ogg/Opus mono 32k y viaja con `voice: true`.

**VALIDADO EN PRODUCCION el 2026-10-30:** la nota se graba, llega con onda y boton de
reproducir, y suena.

**La causa final fue el NOMBRE, no el audio.** Hicieron falta tres cosas y las tres pesaron:
`voice: true`, Ogg/Opus mono, y que el archivo subido **se llame `.ogg`**. Esa ultima fue la que
dejo la nota muda hasta el final: se subia con el nombre que inventa `tempnam()`, sin extension,
y Meta pide que la extension concuerde con el tipo declarado. Las imagenes y los documentos
viajan igual de anonimos desde el temporal de PHP y llegan bien; el audio es mas estricto.

**Dos lecciones:**

1. El copiado de envase (`-c:a copy`) se eligio por elegante - milisegundos, sin perdida - sobre
   un requisito que no habiamos leido. Cuando el formato de salida lo dicta un tercero, la via
   rapida solo vale si cumple TODAS sus condiciones, no la que uno recuerda.
2. Se diagnostico tres veces en falso (conversion, codec, archivo corrupto) antes de dar con
   algo tan tonto como un nombre. Lo que lo resolvio fue mirar los bytes y el `ffmpeg -i` del
   archivo real, no razonar sobre el codigo.

**2. La partida O1.5 (confirmacion de entrega) no estaba construida.** El webhook de Meta busca
los avisos de entrega en `message_log` por `wa_message_id`, y los codigos **no viven ahi** a
proposito. Resultado: los avisos de *entregado*, *leido* y *fallido* de un OTP se descartaban en
silencio, y el panel decia "Enviado" para siempre aunque el telefono estuviera apagado.

Se agrego `otp_codes.delivery_status` + `delivered_at`, **aparte de `status`**: son dos
preguntas distintas y mezclarlas pierde una. Un codigo entregado y nunca usado es normal; uno
que nunca llego, no. Si el mensaje rebota el codigo muere con el. Columna **Entrega** en el
panel y contador en el resumen.

**Como se encontro:** no por un test, sino mirando la cotizacion renglon por renglon contra lo
que habia en produccion. Vale la pena repetirlo al cerrar cada bloque.

### Los archivos guardados nacian ilegibles (2026-10-30)

Todo lo que se guarda en `storage/app/media` venia de un temporal - el de la subida de PHP o el
de `tempnam()` - y esos se crean en **0600**. `rename` conserva el modo, asi que el archivo
quedaba legible **solo para www-data**.

Nada fallaba a la vista: el panel los sirve con PHP, que es www-data. El costo aparecio al
diagnosticar un audio que no se reproducia: `ffmpeg -i` corriendo como `adminsender` devolvia
*Permission denied*, el comando de diagnostico se quedaba callado, y un archivo **ilegible** se
veia exactamente igual que uno **corrupto**. La investigacion se fue media hora al lugar
equivocado.

Dos cambios:

- `MediaStore::guardarArchivo()` hace `chmod 0644` despues de mover. Es `storage/app`, fuera del
  alcance de la web, y quien entra por SSH ya es el administrador.
- `wa:probar-nota-de-voz` **dice por que** cuando no puede leer, en vez de no imprimir nada, y
  sugiere reintentar como www-data. Un diagnostico que calla cuando falla es peor que no
  tenerlo: manda a buscar donde no es.

Segunda vez que un permiso de archivo nos cuesta tiempo (la primera fue el candado del cron, en
septiembre). El patron es el mismo: algo creado por un usuario, leido por otro, sin que nadie
mire el modo.

### M6 / O3 - el numero dedicado, lado panel (2026-10-30)

Lo unico que faltaba de la partida era **poder elegirlo**: el job ya leia el Setting
`otp_phone_number_id` desde que se construyo el API, pero ponerlo exigia `tinker`.

- [x] `GET/PUT /api/settings/otp-phone-number` (solo superadmin, como el resto de Configuracion)
  + tarjeta en la pantalla. Vacio = automatico (el primer numero activo).
- [x] Rechaza un numero apagado o sin token: aceptarlo mostraria en pantalla una cosa y el job
  mandaria por otra, sin que nadie entienda por que.
- [x] No expone Phone IDs de Meta al frontend (regla de seguridad): se elige por el nombre.
- [x] Documentado en `guia-meta.md`, con la trampa de la SIM.
- [ ] **Pendiente del cliente: la SIM.** Debe ser **nueva, nunca registrada en WhatsApp**.

**Lo que este numero separa es la reputacion, no la capacidad.** El limite diario de Meta es por
Business Portfolio y lo comparten todos los numeros (ver M5). Si el cliente espera mas volumen
por tener otro numero, va a esperar mal.

### M2 - verlos en el panel (2026-10-27)

- [x] `GET /api/conversations/media/{conversationId}`: exige sesion, respeta lo del agente, y
  no deja que el archivo se ejecute en nuestro dominio. Se descarto la URL firmada temporal: una
  URL que funciona sin sesion se copia, se comparte y queda en el log de nginx.
- [x] **Lista blanca de tipos que se abren en linea.** Un `image/svg+xml` o un `text/html` que un
  contacto mande como documento SI ejecutan JavaScript en nuestro dominio si se abren en linea,
  y ahi vive el token del operador. Todo lo que no este en la lista se descarga. El PDF se queda
  en linea: el operador necesita revisar el comprobante y el visor del navegador lo aisla.
- [x] El panel pide el archivo con `fetch` y lo convierte en una URL local (`blob:`), porque un
  `<img src="/api/...">` no manda el encabezado de sesion. Las fotos se bajan **cuando entran a
  la pantalla**, no al abrir la conversacion: en un chat con 50 fotos, bajarlas todas de golpe
  tira decenas de peticiones a la vez.
- [x] Burbuja por tipo: foto con miniatura y visor, nota de voz con reproductor, documento y
  video como ficha descargable. Estados *descargando* y *no se pudo recibir*.
- [x] La vista previa de la lista dice "Foto" / "Documento" cuando el mensaje no trae texto.
- [x] **Hueco cerrado de paso:** `GET /api/conversations/{id}` no aplicaba la regla del agente.
  La lista si le escondia lo que no era suyo, pero pidiendo el id a mano un agente leia el chat
  completo de cualquier otro. La regla vive ahora en `Contact::scopeAsignadoA` /
  `esVisibleEnConversacionesPara`, en un solo lugar para lista, detalle y archivos.

**Pendiente de M2 (no bloquea):** el video se entrega como archivo descargable, no con
reproductor en linea. El reproductor necesita soporte de `Range` y subir los limites del
servidor (nginx y PHP estan en 8 MB); va en M4, que es la partida que lo cubre.

### Mejoras de archivos - anotadas, NO programadas

Decision de Alexis (2026-10-27): se dejan como mejoras y se ven si hacen falta cuando el cliente
use el panel. No entran a una tanda todavia.

- [ ] **Zoom en el visor de fotos.** Hoy la burbuja muestra 240 px y el visor crece hasta 760 px
  de ancho (70% de la altura). No hay zoom. **El caso que lo pediria:** leer el CURP o un
  domicilio en la letra chica de una INE fotografiada - a 760 px no se alcanza a leer, y el
  operador tiene que descargar la foto y abrirla con el visor de su computadora. Dos salidas:
  (A) boton "Abrir en pestana nueva", 3 lineas, da el zoom nativo del navegador (rueda para
  acercar, arrastrar para moverse, resolucion completa); (B) componente `Image` de PrimeVue (ya
  instalado, v4), trae zoom y **rotar** - rotar sirve porque la gente fotografia documentos de
  lado - pero reemplaza el visor actual y se pierden el boton Descargar y el nombre en el
  encabezado. Recomendado: A, y rotar aparte si lo piden.
- [ ] **Miniatura de las fotos.** La burbuja baja el original (100-500 KB) y lo encoge con CSS.
  Una miniatura WebP de 400 px pesaria 15-30 KB. **Importante: NO se comprime el original.**
  WhatsApp ya comprime las fotos en el telefono antes de mandarlas (una de 5.68 MB llega como
  1.43 MB, lo normal es entre 100 y 500 KB), asi que lo que recibimos ya es un JPEG con perdida;
  volverlo a comprimir seria perdida generacional - tira detalle sobre el detalle ya tirado, y
  ahorra casi nada. El diseno correcto es original intacto + miniatura aparte. **Esto gana
  velocidad de pantalla, no disco:** si el 5% de los 14,908 contactos manda una foto al mes son
  ~224 MB/mes (~2.7 GB/ano) sobre 192 GB libres. Se decidio esperar a **M3**, donde una libreria
  de imagenes deja de ser opcional (ver abajo).

### Topes de Meta - verificados en su documentacion (2026-10-27)

Esto es lo que un contacto nos puede mandar. Fuente: doc oficial de Cloud API, no blogs.

| Tipo | Tope | Formatos |
|---|---|---|
| Imagen | **5 MB** | JPEG, PNG (8-bit RGB/RGBA) |
| Audio | **16 MB** | AAC, AMR, MP3, M4A, OGG/Opus |
| Video | **16 MB** | MP4, 3GPP (H.264 + AAC) |
| **Documento** | **100 MB** | PDF, DOC/DOCX, XLS/XLSX, PPT/PPTX, TXT |
| Sticker | 100 KB estatico / 500 KB animado | WebP |

- **Codigo de error nuevo: `131052`** - archivo entrante que pasa de 100 MB. NO esta en nuestro
  catalogo de errores traducidos (`DeliveryReason`), asi que hoy saldria en ingles al operador.
- **El peso NO esta en las fotos, esta en los documentos.** Un PDF de 40 MB pesa como 133 fotos,
  y un PDF no se comprime sin rasterizarlo (deja de ser texto seleccionable y se ve borroso al
  hacer zoom, justo donde importan las cifras de un comprobante). El disco se controla con tope
  de tamano y retencion, no comprimiendo.

### Riesgos abiertos de M1/M2 - sin arreglar

- [x] **RESUELTO (2026-10-27): la descarga baja a disco, no a memoria.** `downloadMedia` usa
  `Http::sink()` y devuelve la **ruta** de un temporal en vez del contenido; `MediaStore` tiene
  un `guardarArchivo()` que lo **mueve** en lugar de releerlo (releerlo anularia el streaming
  justo en el ultimo paso). Un documento de 100 MB usa ahora la misma memoria que uno de 1 MB.
  - **Correccion de lo que se dijo antes:** se afirmo que el worker podia "atorar la cola de
    envios". Al verificar la configuracion real (`queue:work --tries=3 --max-time=3600`, sin
    `--memory`), si un job revienta por memoria **Supervisor revive el worker en segundos**: la
    cola no se queda trabada. El costo real era el archivo perdido y ruido en el log. Era
    menos grave de lo que se pinto.
  - **El tope quedo en 100 MB, no en 20.** Se propuso 20 MB como medida de seguridad, pero con
    streaming el tamano ya no es un riesgo de memoria, asi que el tope paso a ser decision de
    disco - y ahi 20 MB **rechazaria comprobantes legitimos** (un PDF de varias paginas
    escaneado con el telefono llega facil a 15-25 MB). Se puso el mismo limite de Meta para
    documentos: nunca rechazamos algo que Meta si acepto. **Alexis lo confirmo en 100 el
    2026-10-27** ("tenemos buen espacio"), sabiendo que nuestro tope ahi casi nunca se va a
    disparar - Meta ya rechaza lo que pasa de 100 MB con el `131052` antes de que nos llegue.
    Se queda como red por si Meta sube su limite (en la app de consumidor ya van en 2 GB) y
    porque el disco es nuestro, no de un tercero. El disco se controla con la retencion, no
    rechazando archivos. **No volver a proponer bajarlo sin un argumento nuevo.**
  - **El tope se revisa ANTES de bajar un byte.** El codigo ya pedia la ficha del archivo a
    Meta, y ahi viene `file_size`. Rechazar algo monstruoso no cuesta ni ancho de banda ni
    tiempo. Hay test de que la URL de descarga no se pide.
  - **El temporal vive bajo `storage/app/tmp/media`**, el mismo sistema de archivos que su
    destino, para que moverlo sea instantaneo (solo cambia el nombre). Entre dos discos
    distintos `rename` falla y PHP tendria que copiar byte por byte; hay respaldo para ese caso.
  - **Basura: `media:limpiar-temporales`** (scheduler, diario 04:30). Los fallos previsibles ya
    borran su temporal en codigo (la descarga y el job), pero ningun `try/catch` alcanza que al
    worker lo maten en seco - `kill -9`, memoria agotada, reinicio del servidor. Ahi el `.part`
    se quedaria para siempre sin nombre por el que buscarlo. Borra lo de mas de 24h; una
    descarga en curso no se toca (su tope es de 120 segundos).
  - **`131052` ya esta en `DeliveryReason`** (archivo entrante > 100 MB). Antes ese caso habria
    salido en ingles al operador.
  - **El aviso de la burbuja se reescribio.** Decia "pidele al contacto que lo reenvie", que es
    mal consejo si el archivo se rechazo por pesado: volveria a llegar igual de pesado. Ahora
    nombra las dos causas. La guia del operador quedo alineada.
  - Sigue sin probarse con un archivo grande de verdad en produccion: lo que hay son 14 tests
    (`MediaDownloadStreamingTest`), que es otra cosa.
- [ ] **Nada borra nunca.** El plazo de retencion lo definira el cliente al final, pero el
  mecanismo tampoco existe. Conviene tener el comando listo y apagado en vez de acordarse con el
  disco al 90%. La ruta de guardado ya quedo preparada para esto: `media/ANO/MES/{contacto}/`
  permite borrar por fecha y tambien todo lo de una persona de golpe (derecho de supresion).
- [ ] **Libreria de imagenes: se decide en M3, no antes.** En M3 el operador va a subir fotos de
  su telefono de 8-12 MB y **el tope de Meta para imagen es 5 MB**: sin redimensionar, el envio
  falla. Ahi el procesamiento deja de ser mejora y pasa a requisito. Ojo con el precedente:
  `TemplateImage::MAX_KB = 5120` y el controlador **rechaza** lo que se pasa - para la imagen de
  plantilla esta bien (la sube un admin una vez), pero en M3 rechazar significa decirle al
  operador "no puedes mandar la foto que tienes", y eso choca con el principio de ser a prueba de
  errores del cliente. Opciones evaluadas: **GD** (ya viene en PHP, un `apt install php8.2-gd`,
  sin FFI; es el mas flojo de los tres pero hace el unico trabajo que se necesita - reducir y
  guardar; **trampa: `imagewebp()` falla en silencio y escribe un archivo corrupto si PHP no se
  compilo con WebP**, o sea que funciona en local y se rompe en el VPS si no se verifica);
  **Imagick** (mas formatos, y `jpeg:size` decodifica ya reducido = memoria plana, aunque esa
  evidencia son notas de usuarios del manual, no doc oficial); **libvips** (el mejor en memoria y
  velocidad, su autor reporta 4x mas rapido y 10x menos memoria que Imagick, pero necesita FFI y
  compilar - resuelve un problema de volumen que a esta escala no tenemos). Con GD hay que leer
  las dimensiones con `getimagesize()` **antes** de decodificar (solo lee el encabezado) y
  saltarse la miniatura si el conteo de pixeles es absurdo, para acotar la memoria por diseno.
