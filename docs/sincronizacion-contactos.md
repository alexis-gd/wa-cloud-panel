# Sincronización de contactos desde el API del cliente

Un cron diario consulta un API **del cliente** (no nuestro) y da de alta en el panel los
contactos que todavía no existen. Solo agrega: nunca actualiza ni reactiva a nadie.

> ⚠️ **Estado: montado, falta el último dato.** Ya sabemos el puerto (8001), el login
> (`POST /login`, JWT) y el recurso (`GET /clients`). Falta correr `contactos:probar-api`
> para ver cómo se llama el arreglo dentro de la respuesta y los campos de teléfono y
> nombre. Todo eso se ajusta en el `.env`, sin tocar código.

---

## Cómo conectarlo cuando lleguen los datos

### 1. Llenar el `.env`

```env
SYNC_API_URL=http://192.168.17.20:8001/clients
SYNC_API_LOGIN_URL=http://192.168.17.20:8001/login
SYNC_API_AUTH=login
SYNC_API_USER=sender
SYNC_API_PASSWORD=...
SYNC_API_ROOT=data          # ajustar con lo que diga contactos:probar-api
```

> 🔑 **El token dura 5 minutos.** El API entrega un JWT (`exp - iat = 300s`), así que **no**
> se puede dejar un token fijo en el `.env`: caducaría mucho antes del siguiente cron. Por eso
> el modo es `login`: hace `POST /login` con usuario y contraseña y usa el token que reciba,
> en cada corrida. El campo del usuario se llama `usuario` (no `username`), lo cual es
> configurable con `SYNC_API_LOGIN_USER_KEY`.

### 2. Probar la conexión

```bash
php artisan contactos:probar-api
```

No escribe nada. Dice si llega, si autentica y **qué campos trae** cada registro:

```
Encontré 1,204 registro(s).

Primer registro tal como llega:
{ "idCliente": 8891, "nombreCompleto": "Juan Pérez", "celular": "9231311146" }

Campos disponibles: idCliente, nombreCompleto, celular
```

### 3. Ajustar el mapeo con esos nombres

```env
SYNC_FIELD_PHONE=celular
SYNC_FIELD_NAME=nombreCompleto
```

Se pueden poner **varios separados por coma** y gana el primero que traiga dato:
`SYNC_FIELD_PHONE=celular,telefono,phone`. Así un cambio de nombre del lado de ellos no
rompe la sincronización. Tampoco distingue mayúsculas: `Telefono` y `telefono` son lo mismo.

Si la lista viene anidada, hay que decir dónde:

```env
# { "result": { "items": [ {...} ] } }
SYNC_API_ROOT=result.items
```

Vacío significa que la respuesta **es** el arreglo.

### 4. Ver qué haría, sin escribir

```bash
php artisan contactos:sincronizar --dry-run
```

```
MODO SECO: no se va a escribir nada en la base de datos.

 Concepto                     Cantidad
 Registros recibidos del API  1204
 Teléfonos válidos            1198
 Con formato inválido         6
 Ya existían en el panel      1150
 Se DARÍAN de alta            48
```

### 5. Correrlo de verdad

```bash
php artisan contactos:sincronizar
```

Y ya queda solo: el scheduler lo corre **todos los días a las 4:00 AM** (hora de México),
antes del warm-up de las 5:00 y de la ventana de envíos de las 9:00, para que lo que entre
esté disponible para las campañas del día.

---

## Reglas que aplica

| Situación | Qué hace |
|---|---|
| Teléfono que no existe | Lo da de alta con `status = active` y `source = api` |
| Teléfono que **ya existe** | Lo ignora. **No** actualiza el nombre: el panel manda sobre el API |
| Contacto **dado de baja** | Lo ignora. La baja manda sobre cualquier fuente externa (LFPDPPP y políticas de Meta) |
| Contacto **eliminado** | Lo ignora. `phone` es UNIQUE y el borrado sigue ocupando el número |
| Teléfono ilegible | Lo cuenta como inválido, muestra la fila y **sigue** con los demás |
| Mismo teléfono repetido | Entra una sola vez |

### Etiquetar lo que entra por aquí

```env
SYNC_TAG=Del sistema
```

Con eso, cada contacto que da de alta el cron recibe esa etiqueta y se puede segmentar una
campaña solo para ellos. Vacío = sin etiqueta.

---

## Cuando algo falla

`contactos:probar-api` distingue los casos, porque se arreglan de formas distintas:

| Síntoma | Qué revisar |
|---|---|
| "Login fallido" HTTP 401 | `SYNC_API_USER` y `SYNC_API_PASSWORD` |
| "Login respondió OK pero no traía token" | `SYNC_API_TOKEN_PATH` |
| No hubo respuesta | Puerto, ruta o **firewall del servidor del cliente** |
| HTTP 401 en la consulta | El token caducó o el usuario no tiene permiso sobre ese recurso |
| HTTP 404 | `SYNC_API_URL`: el servidor contesta pero esa ruta no existe |
| "No encuentro una LISTA" | `SYNC_API_ROOT`: la respuesta llegó pero el arreglo está en otro lado |
| Muchos "formato inválido" | `SYNC_FIELD_PHONE`: el teléfono viene en un campo con otro nombre |

El cron falla solo y no arrastra al resto del scheduler. Los errores quedan en
`storage/logs/laravel.log`.

---

## Lo que ya se sabe del API (verificado 2026-09-02)

| Dato | Valor |
|---|---|
| Base | `http://192.168.17.20:8001` |
| Stack | Node/Express detrás de nginx (los headers son los de **helmet.js**) |
| Login | `POST /login` con `{"usuario": "...", "password": "..."}` → `{"success":true,"token":"<JWT>"}` |
| Vida del token | **5 minutos** (`exp - iat = 300`) |
| Recurso | `GET /clients` (respondía 401 sin token: la ruta existe) |
| Envoltura | `{"success": ..., ...}` - probablemente `{"success":true,"data":[...]}` |
| Rutas que NO existen | `/api`, `/api/v1`, `/clientes`, `/contactos`, `/swagger`, `/health` (todas 404) |

Falta confirmar con `contactos:probar-api`: cómo se llama el arreglo dentro de la respuesta
(`SYNC_API_ROOT`) y los nombres de los campos de teléfono y nombre.

---

## Estado de la red (verificado 2026-09-02)

El VPS del panel (`192.168.17.19`) y el servidor del API (`192.168.17.20`) están en la
**misma subred**: no hace falta túnel ni exponer nada a internet.

```
ip route get 192.168.17.20  →  dev eth0 src 192.168.17.19   ✅ misma red
ping                        →  3/3, 0% pérdida, 0.7 ms      ✅ viva
arp                         →  00:15:5d:...  = VM Hyper-V (Windows)
puerto 8001                 →  ABIERTO                      ✅
```

**No hace falta tocar el firewall**: el 8001 ya está abierto hacia el `.19`. Los demás
puertos (80, 443, 3389, etc.) están cerrados a propósito, y así está bien.

---

## Notas de implementación

| Pieza | Dónde |
|---|---|
| Configuración | `config/contact_sync.php` |
| Login (JWT de vida corta) | `ExternalContactsClient::login()`, se pide uno por corrida |
| Salida HTTP (única) | `App\Services\Contacts\ExternalContactsClient` |
| Reglas de alta | `App\Services\Contacts\ContactSyncService` |
| Comando del cron | `App\Console\Commands\SyncExternalContacts` |
| Diagnóstico | `App\Console\Commands\ProbeExternalContactsApi` |
| Horario | `App\Console\Kernel`, 04:00 CST |

- Ninguna llamada a ese API se hace con `Http::` suelto: todo pasa por
  `ExternalContactsClient`, mismo patrón que `WhatsAppClient` y `SmsGatewayClient`.
- Los tests usan `Http::fake()` y **nunca** tocan el API real.
- Nada se hace fila por fila: los teléfonos existentes se consultan por lotes de 500 y las
  altas van con `insert()` masivo.
- El comando de diagnóstico **nunca imprime la contraseña**, solo si está configurada o no.
