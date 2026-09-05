# Sincronización de contactos desde el API del cliente

Un cron diario consulta un API **del cliente** (no nuestro) y da de alta en el panel los
contactos que todavía no existen. Solo agrega: nunca actualiza ni reactiva a nadie.

> ✅ **Estado: listo para conectar.** Ya se conoce el API completo (puerto, login, recurso y
> campos). Solo falta poner las variables en el `.env` del VPS y decidir con el cliente qué
> estados de cartera se dan de alta.

---

## Cómo conectarlo

### 1. Llenar el `.env`

```env
SYNC_API_AUTH=login
SYNC_API_LOGIN_URL=http://192.168.17.20:8001/login
SYNC_API_URL=http://192.168.17.20:8001/clients
SYNC_API_USER=sender
SYNC_API_PASSWORD=...
SYNC_API_ROOT=data
SYNC_FIELD_PHONE=Celular
SYNC_FIELD_NAME=Nombre
```

Esos son los valores **reales**, ya verificados contra su API.

> 🔑 **El token dura 5 minutos.** El API entrega un JWT (`exp - iat = 300s`), así que **no**
> se puede dejar un token fijo en el `.env`: caducaría mucho antes del siguiente cron. Por eso
> el modo es `login`: hace `POST /login` con usuario y contraseña y usa el token que reciba,
> en cada corrida. El campo del usuario se llama `usuario` (no `username`), lo cual es
> configurable con `SYNC_API_LOGIN_USER_KEY`.

Los nombres de campo admiten **varios separados por coma** y gana el primero que traiga
dato: `SYNC_FIELD_PHONE=Celular,telefono,phone`. Así, si mañana cambian el nombre del campo,
la sincronización sigue jalando. Tampoco distingue mayúsculas.

### 2. Probar la conexión

```bash
php artisan contactos:probar-api
```

No escribe nada. Prueba el login y la consulta **por separado**, para que si algo falla se
sepa cuál de los dos fue, y muestra los campos y los estados que trae el API.

### 3. Ver qué haría, sin escribir

```bash
php artisan contactos:sincronizar --dry-run
```

```
MODO SECO: no se va a escribir nada en la base de datos.

 Concepto                     Cantidad
 Registros recibidos del API  14872
   Con formato inválido       4
   Repetidos en la respuesta  110
   Excluidos por su estado    0
   Teléfonos válidos          14758
 ---------------------------------
 Ya existían en el panel      3039
 Se DARÍAN de alta            11719
 Cambiarían de estado         3039
```

Los cuatro renglones sangrados **suman exactamente** los registros recibidos:

```
recibidos = inválidos + repetidos + excluidos + válidos
```

Si no cuadran, el comando lo avisa. Sin el renglón de repetidos la tabla no cerraba y se
perdían filas sin explicación - en la primera corrida real fueron 110 de 14,872 (el mismo
teléfono más de una vez en su respuesta: la misma persona registrada dos veces, o dos
personas compartiendo número). Gana la primera aparición, y el desglose por estado también
la cuenta una sola vez.

### 4. Correrlo de verdad

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
| Teléfono que **ya existe** | No lo da de alta otra vez y **no** actualiza el nombre: el panel manda sobre el API. Lo único que se le refresca es el estado de cartera |
| Contacto **dado de baja** | Lo ignora. La baja manda sobre cualquier fuente externa (LFPDPPP y políticas de Meta) |
| Contacto **eliminado** | Lo ignora. `phone` es UNIQUE y el borrado sigue ocupando el número |
| Teléfono ilegible | Lo cuenta como inválido, muestra la fila y **sigue** con los demás |
| Mismo teléfono repetido | Entra una sola vez |

### El campo `Estado`: su cartera, no nuestro opt-out

El API trae un campo `Estado` con la clasificación de cartera del cliente. Valores vistos
(conteo real del 2026-09-05, sobre 14,872 registros):

| Estado | Registros | Qué significa (de su lado) |
|---|---|---|
| `LIQUIDADO` | 12,737 | Ya pagó su crédito. Es el mejor prospecto para renovación. |
| `ACTIVO` | 1,704 | Tiene un crédito vigente con ellos. |
| `BURÓ` | 414 | Está reportado en buró de crédito. |
| `BAJA` | 17 | Terminó su relación con ellos. |

> `ACTIVO` no aparecía en la muestra inicial de 3 registros: se descubrió al conectar contra
> el API completo. Es el segundo grupo más grande. No se filtra por nombre de estado en
> ningún lado del código, así que un valor nuevo entra solo - por eso el desplegable de
> Cartera se arma con lo que hay en la base y no con una lista fija.

> 🛑 **Su `BAJA` NO es nuestra Baja.** En el panel, "Baja" significa que la persona pidió
> dejar de recibir mensajes (opt-out, irreversible, legal). En su sistema significa que el
> cliente ya no es cliente. Son cosas distintas: alguien en `BAJA` entra al panel como
> **Activo** y sí puede recibir campañas.

**Por default no se excluye a nadie.** Descartar en silencio sería peor que dar de alta de
más: la decisión es del cliente. Lo que sí se hace es guardar el estado en la columna
**`contacts.portfolio_status`**, que el panel muestra como **Cartera** y permite filtrar.

### Por qué columna y no etiqueta

La primera versión creaba una etiqueta por estado (`LIQUIDADO`, `BURÓ`...). Se cambió a columna
por tres razones:

1. **El estado cambia con el tiempo.** Quien hoy está en `BURÓ` mañana liquida. Como etiqueta
   quedaba congelado en el valor con el que entró, así que el segmento mentía más cada mes -
   justo en `LIQUIDADO`, que es el grupo que le importa al cliente.
2. **Las etiquetas son del operador.** Él las crea, renombra y borra. Un dato que escribe un
   proceso automático no debe vivir donde alguien lo puede borrar sin querer.
3. **Choque de nombres.** Si mañana su API agrega un campo `tag` propio, entraría al mismo
   catálogo y se mezclaría con los estados de cartera.

**Se refresca en cada corrida.** Es lo **único** que la sincronización actualiza de un contacto
que ya existe: el nombre, la baja y todo lo demás siguen intocables.

```env
SYNC_REFRESH_STATUS=true      # default: el estado dice cómo está HOY
SYNC_REFRESH_STATUS=false     # congela el estado con el que entró cada quien
```

### Cómo se manda una campaña a un grupo de la cartera

Las campañas siguen segmentando por **etiqueta**, no por estado de cartera. El flujo es:

1. En Contactos, filtrar por **Cartera = LIQUIDADO**.
2. Botón **Etiquetar todo lo filtrado** → le pone una etiqueta (ej. `Renovación septiembre`) a
   todos los del filtro, no solo a los de la página. Pide confirmación con el total.
3. Crear la campaña con esa etiqueta.

Así la columna dice **cómo está hoy** la persona, y la etiqueta registra **a quién se le mandó**,
aunque su situación cambie después.

Si el cliente decide excluir alguno:

```env
SYNC_STATUS_EXCLUDE=BURÓ                 # nunca dar de alta estos
SYNC_STATUS_INCLUDE=LIQUIDADO            # o al revés: solo estos
```

`EXCLUDE` gana sobre `INCLUDE` (la regla más restrictiva manda) y no distingue mayúsculas.

> **El filtro es una puerta de entrada, no un apagador.** A quien ya está dentro se le sigue
> refrescando el estado aunque quede excluido. Si no fuera así: el cliente excluye `BURÓ`,
> Juan cae en buró, y el panel lo dejaría marcado `LIQUIDADO` para siempre - entrando a la
> campaña de renovación. Excluir impide **dar de alta**, no impide **reflejar la verdad**.

El desglose por estado sale en `--dry-run`, que es justo lo que hace falta para llevarle
números al cliente antes de decidir:

```
Desglose por estado en el sistema del cliente:
 Estado      Registros
 BAJA        340
 BURÓ        128
 LIQUIDADO   1,436
```

### Etiquetar todo lo que entra por aquí

```env
SYNC_TAG=Del sistema
```

Etiqueta adicional para **todos** los que da de alta el cron, sin importar su estado.
Vacío = sin etiqueta.

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
| Entraron menos de los esperados | `SYNC_STATUS_EXCLUDE` / `SYNC_STATUS_INCLUDE`: revisa el renglón "Excluidos por su estado" |

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
| Envoltura | `{"success":true,"data":[ ... ]}` → `SYNC_API_ROOT=data` |
| Campos | `Celular`, `Nombre`, `Estado` |
| Rutas que NO existen | `/api`, `/api/v1`, `/clientes`, `/contactos`, `/swagger`, `/health` (todas 404) |

Respuesta real:

```json
{"success":true,"data":[
  {"Celular":"6692406890","Nombre":"PATRICIA MARIA RIVERA PAZ","Estado":"BAJA"},
  {"Celular":"6691655905","Nombre":"CARLOS OSUNA VEGA","Estado":"LIQUIDADO"},
  {"Celular":"6699931652","Nombre":"PERLA TERESA ORTIZ GOMEZ","Estado":"BURÓ"}
]}
```

Los teléfonos vienen a 10 dígitos sin lada de país; `Contact::normalizePhone()` les antepone
el `52`, igual que en el importador de Excel y el alta manual.

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
