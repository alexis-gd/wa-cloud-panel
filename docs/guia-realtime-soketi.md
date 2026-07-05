# Tiempo real con Soketi (WebSockets)

El panel muestra en vivo las respuestas de los contactos (y mas adelante progreso de campanas,
campanita, semaforo) via WebSocket, sin recargar. El servidor WS es **Soketi**, compatible con el
protocolo Pusher. Laravel le habla por el driver `pusher`; el frontend lo escucha con `laravel-echo`.

> **Aditivo y sin riesgo:** si Soketi no corre o falta la config, la app funciona igual que siempre
> (`BROADCAST_DRIVER=log` = apagado). El tiempo real solo se enciende cuando esto esta configurado.
> El dia que se suba a Laravel 11 se cambia Soketi por Reverb reusando TODO este codigo.

---

## Piezas

| Pieza | Que hace |
|---|---|
| `pusher/pusher-php-server` (composer) | Laravel publica los eventos al servidor WS |
| `laravel-echo` + `pusher-js` (npm) | El navegador se suscribe y escucha (`resources/js/echo.js`) |
| **Soketi** (proceso Node aparte) | El servidor WS que mantiene las conexiones abiertas |
| `BroadcastServiceProvider` | Auth de canales privados por Sanctum (`POST /broadcasting/auth`) |

Evento actual: `App\Events\InboundMessageReceived` -> canal privado `conversations` -> el front lo oye
como `.inbound.message` en `ConversationsView`.

---

## Variables .env (encender el tiempo real)

```env
BROADCAST_DRIVER=pusher

PUSHER_APP_ID=wacloud
PUSHER_APP_KEY=wacloudkey
PUSHER_APP_SECRET=<un-secreto-largo>      # inventalo, debe coincidir con Soketi
# Local:
PUSHER_HOST=127.0.0.1
PUSHER_PORT=6001
PUSHER_SCHEME=http
# Prod (detras de Nginx con TLS):
# PUSHER_HOST=sender.prestamaz.site
# PUSHER_PORT=443
# PUSHER_SCHEME=https

VITE_PUSHER_APP_KEY="${PUSHER_APP_KEY}"
VITE_PUSHER_HOST="${PUSHER_HOST}"
VITE_PUSHER_PORT="${PUSHER_PORT}"
VITE_PUSHER_SCHEME="${PUSHER_SCHEME}"
```

> Tras cambiar los `VITE_*` hay que recompilar el front (`npm run build`), porque Vite los hornea en el bundle.

---

## Local (desarrollo)

```bash
# 1. Levantar Soketi (una terminal mas). Usa las mismas credenciales del .env:
SOKETI_DEFAULT_APP_ID=wacloud \
SOKETI_DEFAULT_APP_KEY=wacloudkey \
SOKETI_DEFAULT_APP_SECRET=<el-mismo-secreto> \
npx @soketi/soketi start --port 6001

# 2. En el .env: BROADCAST_DRIVER=pusher + los PUSHER_* de arriba (host 127.0.0.1, puerto 6001, http)
# 3. npm run build   (o npm run dev)
# 4. Que la cola corra (los broadcasts pasan por la cola): php artisan queue:work
```

Sin Soketi levantado, todo sigue funcionando; solo no hay vivo.

---

## Produccion (VPS)

### 1. Instalar Soketi (Node ya esta en el server)

```bash
sudo npm install -g @soketi/soketi
```

### 2. Correrlo como servicio con Supervisor (igual que la cola)

`/etc/supervisor/conf.d/soketi.conf`:

```ini
[program:soketi]
process_name=%(program_name)s
command=soketi start
environment=SOKETI_DEFAULT_APP_ID="wacloud",SOKETI_DEFAULT_APP_KEY="wacloudkey",SOKETI_DEFAULT_APP_SECRET="<el-mismo-secreto-del-.env>",SOKETI_PORT="6001"
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/soketi/soketi.log
; Rotacion por tamano de Supervisor: nunca un solo archivo gigante.
; Rota al llegar a 10MB y guarda 7 archivos viejos (~70MB tope total).
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=7
```

```bash
sudo mkdir -p /var/log/soketi && sudo chown www-data:www-data /var/log/soketi
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start soketi
```

**Rotacion por dia (opcional, ademas del tope de tamano).** Soketi no es chismoso (solo loguea
conexiones), asi que el tope de tamano de arriba ya basta. Si ademas la quieres **por dia**, crea
`/etc/logrotate.d/soketi`:

```
/var/log/soketi/*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    copytruncate
}
```

`copytruncate` evita reiniciar Soketi al rotar. `rotate 14` = guarda 14 dias.

### 3. Nginx: exponer el WebSocket bajo el dominio (wss)

Soketi escucha en `127.0.0.1:6001`. Se expone por el mismo dominio (`sender.prestamaz.site`) para que el
navegador use `wss://` en el puerto 443. En el `server {}` del sitio, agregar:

```nginx
# WebSocket de Soketi (protocolo Pusher usa /app/<key> y /apps/<id>)
location /app/ {
    proxy_pass http://127.0.0.1:6001;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 3600s;
}
location /apps/ {
    proxy_pass http://127.0.0.1:6001;
    proxy_set_header Host $host;
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

> **Cloudflare:** si el dominio esta proxeado (nube naranja), Cloudflare permite WebSockets por default en 443
> (wss). No hace falta tunel aparte. Si diera problemas, probar con la nube gris (DNS only) para descartar.

### 4. .env de prod + build + deploy

```env
BROADCAST_DRIVER=pusher
PUSHER_HOST=sender.prestamaz.site
PUSHER_PORT=443
PUSHER_SCHEME=https
# (mismos APP_ID/KEY/SECRET que Supervisor)
```

`./deploy.sh` ya hace `npm run build`. La cola (`wa-queue`) debe correr para que los broadcasts salgan.

---

## Verificar que funciona

1. Abrir el panel en **Conversaciones** con la consola del navegador abierta (Network -> WS): debe haber
   una conexion `wss://sender.prestamaz.site/app/...` en estado 101/Open.
2. Que un numero de prueba responda por WhatsApp. La respuesta debe aparecer **sola** en el chat y salir un
   toast "Nueva respuesta", sin recargar.
3. Si no llega: revisar `sudo supervisorctl status soketi`, `/var/log/soketi.log`, que la cola corra, y que
   `/broadcasting/auth` responda 200 (auth Sanctum).

---

## Migrar a Reverb en el futuro (cuando se suba a Laravel 11)

Todo el codigo (evento, `echo.js`, listeners en Vue, canales) queda igual. Solo se cambia el servidor WS:
se quita Soketi (Supervisor) y se instala Reverb (`php artisan reverb:start` bajo Supervisor). El driver
`pusher` y las variables se mantienen casi identicas. Ver [[project_reverb_realtime]].
