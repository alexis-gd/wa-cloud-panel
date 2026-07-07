# Plan: Tiempo real (matar todos los pollings)

Estado y hoja de ruta del tiempo real del panel. El transporte es **Soketi** (WebSocket
compatible con Pusher); ver montaje/infra en [guia-realtime-soketi.md](guia-realtime-soketi.md).
Objetivo del cliente/dev: **cero pollings**, todo lo que cambia "por fuera" se ve al instante.

> Reverb es el destino final, pero exige Laravel 11 + PHP 8.2. Hoy el proyecto es Laravel 10.
> Se eligio **Soketi ahora** (funciona en L10/PHP 8.1) y **Reverb despues** con calma. Todo el
> codigo (eventos, echo.js, listeners, canales) se reusa al migrar: solo cambia el server WS.
> Nota: el VPS **ya corre PHP 8.2** (`php8.2-fpm`), asi que el upgrade futuro esta medio adelantado.

---

## Concepto (para no confundirse)

- **1 sola conexion WebSocket** compartida. Por esa tuberia viajan **varios eventos**; cada vista
  escucha los que le tocan. No son "N sockets", es 1 socket + N eventos + N listeners.
- **Regla para decidir si algo merece socket:** "¿cambia por algo que el usuario NO hizo en esta
  pantalla (worker, webhook, otro operador) **y** lo esta viendo **y** le importa que sea ya?"
  Si falta cualquiera de las tres, no lleva socket.
- **Refetch-on-event NO es polling.** Polling = pregunto cada X seg aunque no pase nada. Refetch-on-event
  = solo pido datos cuando el server avisa que hubo actividad (con debounce para no golpear en un blast).
  En reposo: cero llamadas.

---

## Estado actual

### HECHO (v0.19.1, en prod)
- **Conversaciones en vivo.** Evento `App\Events\InboundMessageReceived` -> canal privado
  `conversations` -> `ConversationsView` escucha `.inbound.message` (recarga chat abierto, refresca
  lista, toast). Base: `resources/js/echo.js` (init guardado por `VITE_PUSHER_APP_KEY`),
  `BroadcastServiceProvider` (auth Sanctum en `/broadcasting/auth`), `routes/channels.php` canal
  `conversations` (incluye superadmin - el 403 inicial era por omitirlo).

### HECHO (v0.20.0)
- **Campanas en vivo.** Evento `App\Events\CampaignProgressUpdated` -> canal privado `campaigns`
  -> `CampaignsView` escucha `.campaign.progress` (parchea la fila de la tabla + el modal abierto:
  contadores, barras y estado suben solos; lista de mensajes del modal se refresca on-event debounced).
  Lo dispara `checkAutoComplete()` en ambos jobs (`SendWhatsAppMessage`, `SendSmsMessage`) con throttle
  via `Cache::add("campaign_progress_{id}", 1, 3)` (max 1 cada 3s por campana) + evento final siempre.
  **Polling eliminado**: `CampaignsView` ya no usa `setInterval` de 5s.

### HECHO (v0.21.0)
- **Campanita en vivo.** Evento `App\Events\NotificationCreated` -> canal privado `notifications`
  -> `AppLayout` escucha `.notification.created` (prepende a la lista, sube el badge, toast al instante).
  Lo dispara el hook `booted()`/`created` de `AppNotification` (cubre todos los sitios que crean notifs
  sin repetir codigo). **Polling eliminado**: `AppLayout` ya no usa `setInterval` de 30s; solo carga
  inicial + eventos.

### HECHO (v0.22.0)
- **Dashboard en vivo (bonus, no tenia polling).** Semaforo del numero: evento
  `App\Events\PhoneNumberPaused` (disparado en `PhoneNumber::pauseFor`) -> canal privado `dashboard`
  -> `DashboardView` escucha `.phone.paused` y recarga la salud al instante. Cifras + ultimos mensajes
  + envios al dia: **refetch-on-event debounced** (2s) escuchando `.campaign.progress` en el canal
  `campaigns` (NO polling: en reposo, cero llamadas). El historico mensual NO se refetch.

### HECHO (v0.23.0)
- **Respuestas SMS en vivo.** Se reusa `App\Events\InboundMessageReceived` con `channel='sms'`,
  disparado en `SmsWebhookController::handleInbound` **solo si el remitente ES un contacto** (evita
  el ruido de SMS de operadoras/servicios). `SmsRepliesView` escucha `.inbound.message` en el canal
  `conversations`, filtra `channel==='sms'`, muestra toast y refresca la lista (refetch debounced)
  si esta en pagina 1 sin filtros. `ConversationsView` ahora ignora `channel==='sms'` (separacion
  de canales WA/SMS).
- **Infra Soketi**: Docker en el VPS (`quay.io/soketi/soketi:1.6-16-alpine`), `127.0.0.1:6001`,
  Nginx `location /app/` (Cloudflare termina TLS), `.env` con PUSHER_* (server->127.0.0.1:6001) y
  VITE_PUSHER_* (browser->sender.prestamaz.site:443 wss).

### Pollings que quedan (actualizado 2026-07-06)
**CERO pollings reales.** Los dos que quedaban se eliminaron:

~~`AppLayout.vue:234` - campanita `setInterval(fetchNotifications, 30_000)`.~~ **ELIMINADO en v0.21.0**
(ahora por evento `NotificationCreated`).
~~`CampaignsView.vue:600` - progreso del modal `setInterval` 5s.~~ **ELIMINADO en v0.20.0** (ahora
por evento `CampaignProgressUpdated`).

`ContactsView.vue:594` es un **debounce** (400ms tras teclear el numero para chequearlo 1 vez), NO
polling: **no se toca**.

---

## Roadmap (cada uno su rama, mismo proceso: feature/* -> develop -> main, el usuario deploya)

Patron por feature: **evento backend (ShouldBroadcast) + punto que lo dispara + listener en la vista**.
Reusar el canal `conversations` o crear canal nuevo segun convenga. Recordar auth del canal en
`routes/channels.php` **incluyendo superadmin**. Broadcast va por la cola (no frena ni rompe si Soketi
cae). Tests: al menos que el evento se dispara y su canal/payload (ver `WebhookInboundTest` como molde).

### 1. Campañas (MAS IMPACTO, hacer primero) - mata polling #2 [HECHO v0.20.0]
- Evento `CampaignProgressUpdated` (campaign_id, sent_count, failed_count, total, status).
- Dispararlo donde el worker incrementa contadores / marca completada (ver `SendWhatsAppMessage`,
  `SendSmsMessage`, y el `checkAutoComplete` de Campaign). Cuidado: no emitir 1 por mensaje sin freno
  en un blast de miles -> throttle/coalesce (ej. emitir cada N mensajes o cada X seg por campaña).
- `CampaignsView`: listener actualiza **la fila de la tabla** (columna Progreso + Estado) **y el modal**
  abierto (contadores + barras). **Borrar el `setInterval` (linea ~600)** y su limpieza.
- Resultado: `12/200 -> 13/200...` sube solo en tabla y modal; Ejecutando -> Finalizada solo.

### 2. Campanita - mata polling #1 [HECHO v0.21.0]
- Evento `NotificationCreated` (o reusar) cuando se crea un `AppNotification` (envio fallido, webhook
  caido, numero pausado). Canal privado (roles admin/operator/agent + superadmin).
- `AppLayout`: listener incrementa el badge y agrega la notif a la lista al instante. **Borrar el
  `setInterval(fetchNotifications, 30_000)` (linea 234)**.
- Resultado: la campanita se prende al momento; al abrir el panel la notif ya esta.

### 3. Dashboard (bonus - hoy NO tiene polling, es agregar vida) [HECHO v0.22.0]
- **Semaforo del numero** -> evento directo `PhoneNumberPaused` (instantaneo, es la senal de "para
  campanias"). Merece socket propio.
- **Cifras + Ultimos mensajes + Envios al dia** -> **refetch-on-event debounced**: al llegar un evento
  relevante, volver a pedir `/dashboard/stats`, `/dashboard/messages`, `/dashboard/daily-stats` (max 1
  cada pocos seg). NO calcular contadores en el cliente (se desincronizan).
- **Historico por mes** (`/dashboard/monthly-history`) -> **NO** refetch. Cambia imperceptiblemente,
  nadie lo mira en vivo. Carga 1 vez.
- Resultado: pantalla de pared que se refresca sola con la actividad, sin timers, sin darle boton.

### 4. Respuestas SMS (OPCIONAL, casi gratis) - hoy NO tiene polling [HECHO v0.23.0]
- El evento `InboundMessageReceived` YA soporta `channel` (se paso 'whatsapp'). Falta: dispararlo en
  `SmsWebhookController::handleInbound` con `channel='sms'` (solo para entrantes que SON contacto, ver
  el fix de "Respuestas SMS solo contactos"), y que `SmsRepliesView` escuche y meta la fila + toast.
- Baja prioridad (lista que se revisa de vez en cuando, no un chat).

---

## Pitfalls aprendidos (no repetir)
- **Canal privado debe incluir `superadmin`** en la autorizacion (si no, `/broadcasting/auth` da 403 y
  no llega nada). Alexis se loguea como superadmin.
- Server->Soketi usa `127.0.0.1:6001 http` (PUSHER_*); browser->Soketi usa el dominio `:443 https`
  (VITE_PUSHER_*). NO confundirlos.
- Soketi npm no corre en Node 20 (uWS solo 14/16/18); por eso va por **Docker** (Node 16 dentro).
- Al desplegar por SSH, pegar bloques con `sudo` mezcla lineas -> correr uno por uno o `sudo -v` antes.
