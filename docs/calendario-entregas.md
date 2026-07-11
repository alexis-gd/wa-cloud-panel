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
- [x] **(B) SMS entrantes visibles — "Respuestas SMS"**: los `sms:received` se guardan en `sms_inbound_messages`. Vista `SmsRepliesView` + endpoint `GET /api/sms/inbound` (admin/operator). Solo muestra respuestas de CONTACTOS (`whereNotNull('contact_id')`, esconde ruido de operadora tipo UNOTV/TELCEL). **Evolucionado (v0.25.0)**: vista **AGRUPADA por contacto** (fila expandible con todos sus mensajes) + **detección de interés** (SI/INFO → tag verde "Interesado") además de STOP/BAJA → "Baja automática". Filtro Todas/Interesados/Bajas.
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
- [x] **Aclarado (no bug):** baja (STOP/BAJA) es **cross-channel por ley** - bloquea WhatsApp Y SMS. Solo dedup/cooldown/pospuesto son por canal.
- [x] **Contadores vs estado real (falla post-envío)** — antes `sent_count`/`failed_count` se fijaban al despachar y NO se corregían si el mensaje fallaba después por webhook/reconcile (un SMS `sent`→`failed` quedaba como Enviado 1 / Fallidos 0 → columna "-"). Fix: `MessageLog::markDeliveryFailed()` (atómico, `sent_count--`+`failed_count++` solo si venía contado como enviado; idempotente ante webhooks repetidos; `GREATEST(...,0)`). Usado en WhatsApp (`WebhookController` fallo de entrega), SMS webhook (`handleFailed`) y `sms:reconcile-status`. Ahora lista, tiles del detalle y barra cuadran. (Las filas viejas previas al fix siguen sin motivo/contador retroactivo.)
