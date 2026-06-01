# Prompt para iniciar módulo SMS — WA Cloud Panel

> **Instrucciones**: Copia y pega este documento como primer mensaje en una sesión de Claude Code dentro del proyecto `C:\xampp\htdocs\wa-cloud-panel`. Claude Code implementará la base del módulo SMS sobre la arquitectura existente.

---

## Contexto

Estamos agregando **SMS como segundo canal** al sistema wa-cloud-panel que ya tiene WhatsApp funcionando. El proveedor de SMS es **Twilio**.

**Lee primero estos archivos para tener contexto completo:**
1. `CLAUDE.md` — overview del proyecto y referencias
2. `.claude/rules/contexto-twilio-sms.md` — reglas de Twilio, errores, opt-out, legal, cooldown
3. `docs/sms-referencia.md` — arquitectura multicanal, flujos, anti-duplicado
4. `.claude/rules/seguridad.md` — reglas de seguridad inquebrantables
5. `docs/calendario-entregas.md` — estado actual del proyecto

**Principio clave**: WhatsApp y SMS son canales independientes en ejecución (números, credenciales, queues, reputación) pero comparten infraestructura (contactos, campañas, métricas, panel). Si SMS tiene problemas, WhatsApp no se entera.

---

## Tarea 1: Configuración de entorno

### Variables de entorno
Agregar a `.env.example` (y documentar en `.env`):
```env
# SMS — Twilio
TWILIO_SID=your_account_sid
TWILIO_AUTH_TOKEN=your_auth_token
TWILIO_FROM_NUMBER=+1234567890
TWILIO_STATUS_CALLBACK_URL=/api/sms/webhook/status

# SMS — Configuración
SMS_COOLDOWN_HOURS=24
SMS_MAX_BOUNCES_BEFORE_BLACKLIST=3
SMS_NIGHT_WARNING_START=23
SMS_NIGHT_WARNING_END=7
```

### Instalar SDK Twilio
```bash
composer require twilio/sdk
```

---

## Tarea 2: Migraciones

### Migración: agregar campo `channel` a `message_log`
```
channel ENUM('whatsapp', 'sms') DEFAULT 'whatsapp'
```
Los registros existentes quedan como 'whatsapp' por defecto.

### Migración: agregar campos SMS a `campaigns`
```
channel ENUM('whatsapp', 'sms') DEFAULT 'whatsapp'
sms_body TEXT NULLABLE — texto plano del SMS (NULL para campañas WA)
```

### Migración: agregar campos SMS a `contacts`
```
sms_opt_out BOOLEAN DEFAULT FALSE — el contacto pidió baja de SMS (STOP)
sms_blocked BOOLEAN DEFAULT FALSE — carrier bloqueó (error 30004)
sms_invalid BOOLEAN DEFAULT FALSE — número no existe o línea fija
sms_bounce_count TINYINT DEFAULT 0 — contador de rebotes consecutivos
sms_blacklisted BOOLEAN DEFAULT FALSE — auto-blacklist por 3+ rebotes
```

### Migración: crear tabla `sms_providers`
```
id, name, sid, auth_token (encrypted), from_number, is_active, created_at, updated_at
```
Similar a `phone_numbers` para WA. Token con `cast: 'encrypted'`.

---

## Tarea 3: SmsClient service

Crear `app/Services/Sms/SmsClient.php` — **ÚNICO lugar que hace HTTP a Twilio**.

Siguiendo el mismo patrón que `WhatsAppClient.php`:
- Inyección de dependencias vía constructor
- Método `send(string $to, string $body): array` — envía un SMS y retorna el response de Twilio
- Configura `StatusCallback` en cada envío para recibir delivery reports
- Loguea errores con `Log::error()`, nunca expone tokens
- En desarrollo (trial), los SMS llegan con prefijo "Sent from Twilio Trial account"

```php
// Patrón esperado:
$client = new \Twilio\Rest\Client($sid, $authToken);
$message = $client->messages->create($to, [
    'from' => $fromNumber,
    'body' => $body,
    'statusCallback' => config('app.url') . '/api/sms/webhook/status',
]);
return [
    'sid' => $message->sid,
    'status' => $message->status,
];
```

Regla de seguridad: credenciales se leen de tabla `sms_providers` en producción, de `.env` en desarrollo.

---

## Tarea 4: Job SendSmsMessage

Crear `app/Jobs/SendSmsMessage.php` — job independiente de `SendWhatsAppMessage`.

Antes de enviar, verificar:
1. `contact.sms_opt_out` no es true
2. `contact.sms_blacklisted` no es true
3. `contact.sms_invalid` no es true
4. **Cooldown cross-channel**: no existe message_log para este contact_id en las últimas `SMS_COOLDOWN_HOURS` horas (ni WA ni SMS con status != failed)

Si alguna verificación falla → saltar el contacto, loguear motivo, no enviar.

Si pasa todas → crear registro en `message_log` con `channel = 'sms'` y `status = 'pending'` ANTES de llamar a la API, luego llamar a `SmsClient::send()`.

---

## Tarea 5: Webhook de status SMS

Crear ruta y controller para recibir StatusCallback de Twilio:

### Ruta
```php
Route::post('/api/sms/webhook/status', [SmsWebhookController::class, 'status']);
```
Sin `ApiKeyMiddleware` (Twilio no envía X-API-Key), pero validar que viene de Twilio.

### Controller: `SmsWebhookController`
Recibe:
```
MessageSid, MessageStatus, ErrorCode (nullable)
```

Lógica:
```
SI MessageStatus = "delivered" → message_log.status = delivered
SI MessageStatus = "sent" → message_log.status = sent
SI MessageStatus = "undelivered":
    SI ErrorCode = 30004 → contact.sms_blocked = true, incrementar bounce_count
    SI ErrorCode = 30005 o 30006 → contact.sms_invalid = true
    SI ErrorCode = 30003 → incrementar bounce_count (reintentar 1 vez)
    SI ErrorCode = 30007 → loguear "posible filtro anti-spam", revisar contenido
    message_log.status = failed, message_log.error_code = ErrorCode
SI MessageStatus = "failed":
    SI ErrorCode = 21610 → contact.sms_opt_out = true (usuario envió STOP)
    message_log.status = failed, message_log.error_code = ErrorCode

DESPUÉS de cualquier undelivered/failed:
    SI contact.sms_bounce_count >= SMS_MAX_BOUNCES_BEFORE_BLACKLIST:
        contact.sms_blacklisted = true
```

---

## Tarea 6: Actualizar CampaignController

Modificar el flujo de creación de campaña para soportar ambos canales:

- Recibir `channel` en el request (whatsapp | sms)
- Si `channel = whatsapp`: validar `wa_template_id` + `body_vars` (flujo existente)
- Si `channel = sms`: validar `sms_body` (obligatorio, máx 480 chars para 3 segmentos)
- Agregar footer automático al `sms_body`: "\nResponde STOP para no recibir más" (cumplimiento legal)
- Al ejecutar campaña SMS, despachar `SendSmsMessage` jobs (no `SendWhatsAppMessage`)

### Filtro anti-duplicado al crear campaña
Al seleccionar contactos para una campaña, excluir automáticamente:
- Contactos con `sms_opt_out = true` o `sms_blacklisted = true` (si canal es SMS)
- Contactos con `wa_opt_out = true` (si canal es WA, flujo existente)
- Contactos impactados por CUALQUIER canal en las últimas `SMS_COOLDOWN_HOURS` horas
- Retornar conteo: "245 seleccionados, 12 excluidos (recibieron mensaje ayer)"

### Advertencia nocturna (solo SMS)
Si `channel = sms` y la hora programada está entre `SMS_NIGHT_WARNING_START` y `SMS_NIGHT_WARNING_END`:
- Retornar warning (no bloqueo): "Enviar entre 11PM-7AM puede generar más bajas y filtrado"

---

## Tarea 7: Tests

### Tests Feature

**`tests/Feature/SmsWebhookTest.php`**
- POST `/api/sms/webhook/status` con `MessageStatus = delivered` → actualiza message_log
- POST con `ErrorCode = 21610` → marca contact.sms_opt_out = true
- POST con `ErrorCode = 30004` → incrementa bounce_count
- POST con 3 bounces consecutivos → marca sms_blacklisted = true

**`tests/Feature/SmsCampaignTest.php`**
- Crear campaña SMS sin sms_body → 422
- Crear campaña SMS con sms_body → 201, campaign.channel = 'sms'
- Campaña SMS excluye contactos opt-out
- Campaña SMS excluye contactos impactados en últimas 24h (cooldown)

### Tests Unit

**`tests/Unit/SmsClientTest.php`**
- Mock de Twilio SDK → SmsClient::send() retorna sid + status
- SmsClient::send() con error → no lanza excepción, retorna error code

---

## Tarea 8: Actualizar frontend (Vue)

En la pantalla de crear campaña:
- Agregar selector de canal: [WhatsApp] | [SMS]
- Si WhatsApp → mostrar selector de plantillas (flujo existente)
- Si SMS → mostrar textarea con contador de caracteres (160/320/480 = 1/2/3 segmentos)
- Mostrar preview del SMS con footer "Responde STOP para no recibir más" agregado automáticamente
- Mostrar conteo de contactos elegibles vs excluidos
- Si SMS + horario nocturno → mostrar advertencia amarilla

---

## Orden de ejecución sugerido

1. Lee los archivos de contexto listados arriba
2. `composer require twilio/sdk`
3. Crea migraciones (channel en message_log, channel + sms_body en campaigns, campos SMS en contacts, tabla sms_providers)
4. Corre migraciones: `php artisan migrate`
5. Crea `SmsClient.php`
6. Crea `SendSmsMessage.php` job
7. Crea `SmsWebhookController.php` + ruta
8. Modifica `CampaignController` para soportar ambos canales
9. Crea tests
10. Corre `php artisan test` → todo verde
11. Actualiza frontend (selector de canal + textarea SMS)
12. Actualiza `docs/calendario-entregas.md` con el progreso
13. Commit: "feat: módulo SMS multicanal — Twilio, jobs, webhook, cooldown cross-channel"

---

## Lo que NO hacer en esta sesión

- NO tocar `WhatsAppClient.php` ni `SendWhatsAppMessage.php` — el canal WA ya funciona
- NO cambiar el flujo existente de campañas WA — solo agregar la rama SMS
- NO enviar SMS reales — usar `Http::fake()` en tests y trial de Twilio para pruebas manuales
- NO hardcodear credenciales — todo en .env / tabla sms_providers con encrypted cast
