# Guía — Montar el gateway SMS (SMS Gateway for Android™ / capcom6)

> ✅ **EN USO — probado en producción 2026-07-02.** Este es el camino real del canal SMS (no Twilio).
> Cómo dejar operativo el canal SMS por SIM propia. Ver contexto y decisión en
> [`docs/sms-sim-propia-analisis.md`](sms-sim-propia-analisis.md).

---

## ⚙️ Cómo quedó montado en prod (resumen del deploy real 2026-07-02)

Lo que la guía de abajo describe en general; así quedó en el VPS `sender.prestamaz.site`:

1. **Server**: contenedor `sms-gateway` (`ghcr.io/android-sms-gateway/server:latest`) en Docker,
   `--network host`, `listen 127.0.0.1:3000`, BD `sms_gateway` en el MySQL del VPS. Config en `/opt/sms-gateway/config.yml`.
2. **Exposición**: NO fue por reenvío de puertos ni certbot. El VPS ya tenía un **Cloudflare Tunnel**
   (`cloudflared.service`) para el panel. Se agregó el gateway como **Public Hostname** en ese túnel:
   `gw.prestamaz.site` → `http://127.0.0.1:3000` (SSL lo pone Cloudflare). Lo hizo el admin de Cloudflare.
   - ⚠️ El service del Public Hostname debe ser `http://127.0.0.1:3000` (NO `localhost` ni una IP LAN — si no, 502).
3. **App del teléfono**: Settings → Cloud server → **API URL = `https://gw.prestamaz.site/api/mobile/v1`**
   (¡lleva `/api/mobile/v1`! si pones solo la raíz, el registro POST a `/device` da 404) + Private Token.
   Registro con SIGN UP → Continue. Las credenciales 3rdparty (usuario/pass) se autogeneran y salen en la app.
4. **Panel `.env`**: `SMS_GATEWAY_URL=http://127.0.0.1:3000/api/3rdparty/v1` + LOGIN/PASSWORD autogenerados + `config:cache`.
5. **Número E.164 con `+`**: el gateway lo exige; el panel lo antepone solo (`SmsGatewayClient::toE164()`). El operador escribe solo el número.
6. **Webhook (✅ funcionando)**: registrados los 4 eventos vía 3rdparty API apuntando a
   `https://sender.prestamaz.site/api/sms/webhook`. `sms:sent`/`sms:delivered` actualizan el status
   (→ "Entregado"), `sms:received` con STOP marca opt-out. Payload real: estados usan `messageId`;
   entrantes usan `sender` (no `phoneNumber`). Firma HMAC opcional (`SMS_WEBHOOK_SECRET`, ver §Webhook).
   - ⚠️ **GOTCHA CLAVE**: al registrar/cambiar webhooks, el **teléfono NO los usa hasta re-sincronizar
     su lista**. Los entrega el teléfono, y sincroniza la lista del servidor solo al **reiniciar la app /
     reconectar Cloud server**. Si registras webhooks y no llegan, **reinicia la app del teléfono**.
     (Verificar re-sync: `docker logs sms-gateway | grep "mobile/v1/webhooks"` → debe haber un GET
     con hora POSTERIOR al registro.)

---

## Arquitectura (recordatorio)

```
[Laravel]  ──HTTP──▶  [Servidor Gateway]  ──FCM push──▶  [App Android + chip]  ──▶ SMS
 panel                 (Docker en el VPS)   (vía sms-gate.app)  teléfono
   ▲                                                              │
   └──────────────── webhook /api/sms/webhook ◀───────────────────┘
```

- **El teléfono envía OUT** al servidor para recibir órdenes; el servidor lo despierta con un
  push FCM que **capcom6 maneja gratis vía `api.sms-gate.app`** (el push NO lleva el texto ni el
  número, solo un "tienes trabajo"). Por eso el servidor **necesita URL pública HTTPS** para que
  los teléfonos lo alcancen.
- **El panel (Laravel) habla con el gateway por red interna** (`localhost:3000`) — no necesita
  salir a internet para enviar.

---

## Opción A — Demo rápida (1 teléfono, sin servidor) ⏱️ ~15 min

Para ver UN SMS salir cuanto antes, sin montar Docker:

1. Instala **SMS Gateway for Android** (Play Store o APK del repo) en un teléfono con chip y saldo.
2. En la app, activa **"Local Server"** (modo local). Muestra una IP, puerto, usuario y contraseña.
3. El teléfono y tu máquina/VPS deben estar en la **misma red** (o usa un túnel tipo ngrok al puerto).
4. En `.env`:
   ```
   SMS_GATEWAY_URL=http://IP_DEL_TELEFONO:8080   # el que muestra la app (revisar path, ver §Verificar)
   SMS_GATEWAY_LOGIN=<usuario que muestra la app>
   SMS_GATEWAY_PASSWORD=<password que muestra la app>
   ```
5. `php artisan config:clear` y prueba con el botón **Enviar prueba** (modal de campaña SMS, como admin).

> Local mode sirve para validar el flujo. Para producción (teléfonos en datos móviles, fuera de la
> LAN) necesitas el servidor privado — Opción B.

---

## Opción B — Producción (servidor privado + pool) ⏱️ ~1 h

### 1. Requisitos en el VPS
- Docker + Docker Compose (`sudo apt install docker.io docker-compose-plugin`)
- MySQL 8.0.13+ o MariaDB 10.2.7+ (ya hay MySQL en el VPS; puedes crear una BD `sms_gateway`)
- Un subdominio público con SSL para el gateway, ej. `gw.prestamaz.site` (Nginx proxy → `:3000`)

### 2. Crear `config.yml`
En el VPS, `/opt/sms-gateway/config.yml` (basado en `config.example.yml` del repo):
```yaml
gateway:
  mode: private                       # solo devices con el token pueden registrarse
  private_token: <token-largo-aleatorio>
  upstream_url: https://api.sms-gate.app/upstream/v1   # FCM gestionado por capcom6

http:
  listen: "0.0.0.0:3000"

database:
  dialect: mysql
  host: 127.0.0.1
  port: 3306
  user: sms_user
  password: <password>
  database: sms_gateway

jwt:
  secret: <secreto-minimo-32-caracteres>
  issuer: prestamaz-sms
```
Genera secretos con: `openssl rand -hex 32`

### 3. Levantar el servidor
```bash
cd /opt/sms-gateway
docker run -d --name sms-gateway --restart unless-stopped \
  -p 127.0.0.1:3000:3000 \
  -v ./config.yml:/app/config.yml \
  capcom6/sms-gateway:latest
```
> `-p 127.0.0.1:3000:3000` deja el server solo accesible en local; Nginx lo expone públicamente
> en `gw.prestamaz.site` (con SSL) para que los teléfonos lo alcancen, y Laravel lo llama por
> `http://127.0.0.1:3000`.

### 4. Nginx (subdominio del gateway)
Proxy `gw.prestamaz.site` → `http://127.0.0.1:3000`, con Certbot para SSL (igual que el panel).

### 5. Registrar el/los teléfono(s)
En cada teléfono, en la app SMS Gateway for Android:
- **Settings → Cloud Server** (o "Private Server").
- **Server URL / API URL**: `https://gw.prestamaz.site`
- **Private token**: el `private_token` del `config.yml`
- Activar la conexión. El teléfono aparece en el pool del servidor.
- **⚠️ Las credenciales del 3rdparty API (usuario/contraseña) se AUTOGENERAN** y aparecen en la
  app tras conectar — NO se crean a mano. Esas son las que van en `SMS_GATEWAY_LOGIN/PASSWORD`.
- Repetir en cada teléfono del pool (comparten el mismo token; el server hace round-robin).

---

## Conectar el panel (ambas opciones)

En `.env` del proyecto (ya existen las llaves, solo llénalas):
```
SMS_GATEWAY_URL=http://127.0.0.1:3000/api/3rdparty/v1   # self-host lleva /api
SMS_GATEWAY_LOGIN=<usuario autogenerado al conectar el teléfono>
SMS_GATEWAY_PASSWORD=<password autogenerado al conectar el teléfono>
SMS_WEBHOOK_SECRET=<secreto compartido para el webhook>
```
El cliente le agrega `/messages` → llama `POST {url}/messages` con Basic auth.
Luego: `php artisan config:cache` (en prod) o `config:clear` (en local).

### ⚠️ Verificar contrato (paso obligatorio la primera vez)
El endpoint exacto y el auth de capcom6 varían entre versiones. Con el server arriba, abre su
**Swagger** en `https://gw.prestamaz.site/` (o `/3rdparty/v1`) y confirma:
- La ruta de envío: ¿`POST /message` o `POST /messages`?
- El auth: ¿Basic (login/password) o JWT (token primero)?

Nuestro cliente [`app/Services/Sms/SmsGatewayClient.php`](../app/Services/Sms/SmsGatewayClient.php)
hoy hace `POST {url}/message` con **Basic auth** y body `{message, phoneNumbers}`. Si el Swagger
dice `/messages` (plural) o exige JWT, es un ajuste de **una línea** (la ruta) o del método de auth
en ese archivo. Alternativa robusta: cambiar a la librería oficial
`composer require android-sms-gateway/client` y usar su `Client::send()` (abstrae ruta y auth).

---

## Configurar el webhook (estados delivered/failed + opt-out por SMS)

En el servidor gateway (panel de admin del gateway o su API de webhooks), registra:
- **URL**: `https://sender.prestamaz.site/api/sms/webhook`
- **Eventos**: `sms:sent`, `sms:delivered`, `sms:failed`, `sms:received`
- **Firma**: si el gateway firma con HMAC, usa el mismo valor de `SMS_WEBHOOK_SECRET`. Si no,
  deja `SMS_WEBHOOK_SECRET` vacío (el webhook no exigirá firma — solo para pruebas).

Sin webhook los SMS se marcan `sent` pero nunca `delivered`/`failed`, y el STOP por SMS no marca
opt-out automático.

### Redes de seguridad si el webhook no llega (app del gateway muerta por MIUI)
El webhook lo entrega el TELÉFONO; si MIUI mata la app, se pierden eventos. Dos comandos lo cubren
(scheduler, sin tocar el teléfono a mano):
- **Salientes** (`sms:reconcile-status`, cada 10 min): pregunta al gateway el estado de los SMS en
  `sent` y los pasa a delivered/failed (pull server-a-server).
- **Entrantes** (`sms:reconcile-received`, cada hora): los recibidos viven en el teléfono y NO se
  pueden pollear; el comando le pide re-exportar los `sms:received` de las últimas 24h vía
  `POST {url}/inbox/export`. Vuelven por el mismo webhook y se deduplican por `gateway_message_id`.
  ⚠️ **Verificar en Swagger** que la ruta de export es `POST /inbox/export` (igual que se verifica
  `/messages` para el envío); si difiere, ajustar `SmsGatewayClient::requestInboxExport()`. Opcional:
  `SMS_GATEWAY_DEVICE_ID` en `.env` para pedir la re-exportación a un device concreto (vacío = todos).

---

## Probar

1. Como **admin**, ir a **Campañas → Nueva campaña → Canal: SMS**.
2. Escribir el mensaje, poner tu número en **Enviar prueba** y clic en **Enviar**.
3. Debe llegar el SMS y el toast decir "SMS de prueba enviado".
4. Para blast real: guardar la campaña y **Ejecutar** (SMS no tiene horario forzado).

---

## Agregar más teléfonos al pool (escalar de 1 a varios)

El panel **no se toca**: reparte los SMS llamando al gateway, y el gateway reparte entre los
teléfonos registrados (round-robin). Para sumar un chip solo das de alta el teléfono en el gateway.

**Pasos (por cada teléfono nuevo):**
1. Instalar la app **SMS Gateway for Android** en el teléfono nuevo (con su SIM y saldo).
2. En la app: **Settings → Cloud server → API URL = `https://gw.prestamaz.site/api/mobile/v1`**.
3. Poner el mismo **private token** que los demás (está en `config.yml` del gateway y en `CLAUDE.local.md`).
   Así el teléfono se registra contra tu servidor, no contra el cloud público.
4. Encender el switch **online** en la app. En unos segundos aparece como device nuevo en el gateway.
5. **Blindaje MIUI/Android** (obligatorio, si no la app se muere sola): Autostart **ON**, batería
   **"Sin restricciones"**, bloquear la app en "recientes", WiFi estable y **enchufado**.
6. Verificar que quedó registrado:
   ```bash
   # en el VPS, contra el gateway
   curl -s -u P3ID_H:CONTRASEÑA http://127.0.0.1:3000/api/3rdparty/v1/devices | jq
   ```
   Debe listar el device nuevo. A partir de ahí el gateway ya lo mete al round-robin solo.

**Qué NO hay que hacer:** nada en el panel, ni en `.env`, ni migraciones. El reparto es del gateway.
> Ojo con `SMS_GATEWAY_DEVICE_ID` en el `.env`: **déjalo vacío**. Si lo pones, el reconcile de
> entrantes (`sms:reconcile-received`) solo le pediría respuestas a ESE teléfono. Vacío = a todos.
> (La firma del webhook, si algún día se activa `SMS_WEBHOOK_SECRET`, sí hay que ponerla igual en
> cada teléfono - vive en cada device.)

---

## ¿El problema es un chip o es la app? (cómo distinguir)

Dos fallas distintas, se ven diferente. Regla rápida: **si NINGÚN SMS se entrega, es la app; si solo
algunos, es un chip.**

**Es la APP del gateway (se cayó / MIUI la mató) - afecta a TODOS:**
- Señal en el panel: **Configuración → Salud del webhook SMS** marca caído (`no_hits`/`stale`), y salta
  la alerta en la campanita (`sms:monitor-webhook`).
- Ningún SMS pasa de **Enviado** a **Entregado**, y no entran respuestas nuevas.
- Qué hacer: abrir la app en el teléfono, ponerla **online**, revisar el blindaje MIUI del paso 5.
  El reconcile (`sms:reconcile-status` y `sms:reconcile-received`) recupera lo perdido al volver.

**Es un CHIP/SIM (uno solo del pool) - los demás siguen normal:**
- Los otros teléfonos siguen entregando; solo caen los envíos que tocaron ese chip.
- Señales: sus SMS salen **Fallido** (`sms:failed`) o se quedan sin entregar; sube el contador de
  rebotes concentrado. En el **dashboard del gateway** (o `GET /api/3rdparty/v1/devices`) ese device
  aparece **offline** o sin actividad reciente.
- Causas típicas: SIM sin saldo, sin señal/cobertura, chip bloqueado por la operadora (spam), o la
  operadora topó los ~8 SMS/min por chip.
- Qué hacer: prueba de envío manual desde ESE teléfono; revisar saldo y señal. Si la operadora bloqueó
  el chip, cambiarlo - el resto del pool sigue enviando mientras tanto.

---

## Troubleshooting

| Síntoma | Causa probable | Qué revisar |
|---|---|---|
| Botón dice "el gateway rechazó el envío" | URL/credenciales malas o ruta equivocada | `.env` + §Verificar contrato (Swagger) |
| 401 del gateway | auth incorrecto (Basic vs JWT) | §Verificar contrato |
| SMS queda en `sent`, nunca `delivered` | webhook no configurado | registrar webhook en el gateway |
| El teléfono no envía | no registrado / sin saldo / sin señal | app en modo Private, saldo del chip, cobertura |
| Push no llega al teléfono | `upstream_url` mal o FCM bloqueado | `config.yml` → `upstream_url` |

---

## Fuentes

- Servidor self-host: https://github.com/android-sms-gateway/server
- Docs oficiales: https://docs.sms-gate.app/getting-started/private-server/
- Cliente PHP: https://github.com/android-sms-gateway/client-php
- Imagen Docker: https://hub.docker.com/r/capcom6/sms-gateway
