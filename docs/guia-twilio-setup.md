# Guía: Crear cuenta Twilio y obtener credenciales SMS

> Guía paso a paso. Tiempo estimado: 10-15 minutos.
> No necesitas tarjeta de crédito para el trial.

---

## Paso 1 — Crear cuenta (trial gratuito)

1. Ve a **https://www.twilio.com/try-twilio**
2. Llena el formulario (email, nombre, password)
3. Verifica tu email (link que te envían)
4. Verifica tu número de teléfono mexicano en formato `+521XXXXXXXXXX`
5. Wizard de bienvenida: elige **SMS** → **Marketing campaigns** → **With code** → **PHP**

---

## Paso 2 — Obtener tu número Twilio trial

1. Dashboard → **"Get a Trial Number"** (o Console → Phone Numbers → Buy a number)
2. Twilio asigna un número US (+1...) gratis → ese es tu `TWILIO_FROM_NUMBER`
3. En trial solo puedes enviar a números verificados

---

## Paso 3 — Obtener credenciales API

1. Console Dashboard: https://console.twilio.com/
2. **Account SID** (empieza con `AC...`) → `TWILIO_SID`
3. **Auth Token** (click "Show") → `TWILIO_AUTH_TOKEN`

---

## Paso 4 — Verificar números para pruebas

En trial, solo envías a números verificados previamente.

1. Console → Phone Numbers → Manage → **Verified Caller IDs**
2. "Add a new Caller ID" → número mexicano → verificar por SMS → ingresar código

Verificar al menos: tu número personal, el número del cliente (para demo), 1-2 extras.

---

## Paso 5 — Configurar en `.env`

```env
# SMS — Twilio
TWILIO_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_AUTH_TOKEN=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
TWILIO_FROM_NUMBER=+1XXXXXXXXXX

# SMS — Configuración
SMS_COOLDOWN_HOURS=24
SMS_MAX_BOUNCES_BEFORE_BLACKLIST=3
SMS_NIGHT_WARNING_START=23
SMS_NIGHT_WARNING_END=7
```

---

## Paso 6 — Probar desde tinker

```php
$client = new \Twilio\Rest\Client(env('TWILIO_SID'), env('TWILIO_AUTH_TOKEN'));
$message = $client->messages->create('+521TUTELEFONO', [
    'from' => env('TWILIO_FROM_NUMBER'),
    'body' => 'Prueba wa-cloud-panel. Responde STOP para no recibir más.',
]);
echo $message->sid;    // SM... = éxito
echo $message->status; // queued
```

---

## Limitaciones trial

| Limitación | Detalle |
|---|---|
| Solo números verificados | No puedes enviar a cualquier número |
| 50 msgs/día | Suficiente para desarrollo |
| Prefijo trial | "Sent from a Twilio Trial account" (se quita al pagar) |
| 1 número Twilio | Solo 1 número en trial |
| 30 días inactivo | Pierdes el número si no lo usas |

---

## Cuándo hacer upgrade

Al llegar a **Entrega 2** (demo al cliente). Trial es suficiente para todo el desarrollo.

Costo upgrade: sin mensualidad, prepago mínimo ~$20 USD, ~$0.01 USD/msg a México, ~$1.15 USD/mes por número.

---

## Troubleshooting

| Problema | Solución |
|---|---|
| SMS no llega | ¿Número destino verificado? ¿Formato +521XXXXXXXXXX? ¿<50 msgs hoy? |
| Auth token not valid | Regenerar en Console → Account → API Keys. Actualizar .env + `artisan config:clear` |
| Number not valid | Formato México: +521XXXXXXXXXX (13 dígitos) |
| Permission not enabled | Verificar que el número Twilio tenga SMS habilitado |

---

## Credenciales a guardar

```
TWILIO_SID=            → Console → Account SID
TWILIO_AUTH_TOKEN=     → Console → Auth Token
TWILIO_FROM_NUMBER=    → Console → Phone Numbers → tu número
```
