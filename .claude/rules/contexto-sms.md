# Contexto SMS — Reglas, políticas y decisiones

> Canal complementario a WhatsApp. La prioridad #1 sigue siendo proteger la cuenta WABA.
> SMS es un canal independiente: si SMS tiene problemas, WhatsApp no se entera y viceversa.
>
> **PROVEEDOR ELEGIDO: SIM propia con gateway self-host (SMS Gateway for Android / capcom6).**
> Twilio y SMS Masivos se evaluaron pero NO se eligieron (el cliente rechazó pagar API de proveedor).
> Las reglas de cumplimiento (opt-out, REPEP, horario, cross-channel) aplican **igual** sin importar
> el proveedor. Las secciones de errores/pricing de Twilio abajo son **referencia histórica**.

---

## Proveedor ELEGIDO: SIM propia — gateway capcom6 (self-host)

- **Cómo funciona**: teléfonos Android con chip + servidor gateway (Docker) → el panel llama a
  `SmsGatewayClient::send()` → `POST {url}/api/3rdparty/v1/messages` (Basic auth). El pool de chips
  lo resuelve el gateway (round-robin). Ver [`docs/guia-sms-gateway-setup.md`](../../docs/guia-sms-gateway-setup.md).
- **En producción (desde 2026-07-02)**: gateway corriendo en el VPS, expuesto por Cloudflare Tunnel
  (`gw.prestamaz.site`), 1 teléfono registrado, primer SMS enviado OK (botón de prueba + campaña).
- **Número E.164 con `+`**: el gateway lo exige; `SmsGatewayClient::toE164()` lo antepone.
- **Costo**: hardware (celulares + SIMs) en vez de $/msg. Sin CFDI por SMS. Riesgos asumidos por el
  cliente (bloqueo de SIM, entrega no auditable) — ver [`docs/sms-sim-propia-analisis.md`](../../docs/sms-sim-propia-analisis.md).
- **Pool multi-celular**: sumar teléfonos es solo alta en el gateway (round-robin). El panel NO se
  toca: el envío despacha `SendSmsMessage(contact, campaign, body)` sin `phone_number_id` ni device.

### Redes de seguridad si el webhook no llega (el webhook lo entrega el TELÉFONO, no el server)
Si MIUI mata la app del gateway se pierden eventos. Dos comandos lo cubren (scheduler, server-side):
- **Salientes** — `sms:reconcile-status` (cada 10 min): `SmsGatewayClient::getState()` pregunta el
  estado de los SMS en `sent` y los pasa a delivered/failed. Pull directo server-a-server.
- **Entrantes** — `sms:reconcile-received` (cada hora): los recibidos viven en el teléfono y NO se
  pueden pollear; `SmsGatewayClient::requestInboxExport()` pide re-exportar los `sms:received` de las
  últimas 24h vía `POST {url}/messages/inbox/export` (async, ⚠️ ruta a verificar en Swagger). Vuelven por el
  mismo webhook y se **deduplican por `sms_inbound_messages.gateway_message_id`** (evita filas y
  opt-outs repetidos). El entrante en vivo también guarda ese id, así vivo y re-export comparten llave.
- **Pool**: dejar `SMS_GATEWAY_DEVICE_ID` vacío para que el reconcile re-exporte de TODOS los devices.

## Proveedores evaluados y NO elegidos (referencia)

- **Twilio**: ~$0.01 USD/msg, mejor API/docs/webhooks, pero USD sin CFDI y costo por mensaje. Descartado por costo.
- **SMS Masivos** (smsmasivos.com.mx): CFDI 4.0, ~$0.47 MXN/msg. Reconsiderar solo si el cliente exige CFDI específico por SMS.

---

## Arquitectura — separación de canales

### NUNCA compartir entre WhatsApp y SMS
- **Números de teléfono** — cada canal usa su propio número
- **Credenciales API** — tokens de Meta y tokens de Twilio son mundos separados
- **Warm-up / reputación** — independientes por canal
- **Queue workers** — jobs separados: `SendWhatsAppMessage` y `SendSmsMessage`. Si SMS se atora, WhatsApp sigue

### SÍ compartir (sin riesgo)
- **Base de contactos** — misma tabla `contacts`, campo `phone` sirve para ambos
- **Panel del cliente** — un solo login, un solo dashboard, elige canal al crear campaña
- **Dashboard y métricas** — `message_log` con columna `channel` (whatsapp | sms)
- **Opt-out** — si alguien pide baja, bloquear en AMBOS canales
- **Listas negras** — compartidas. Un número bloqueado no recibe por ningún canal
- **Campañas** — misma tabla `campaigns` con campo `channel`
- **Reportes y exports** — un solo Excel con columna de canal

---

## Estructura de datos SMS

### Tabla `campaigns` — campos específicos SMS
```
channel = "sms"
sms_body = texto plano (máx 160 chars para 1 segmento, 320 para 2, etc.)
wa_template_id = NULL (no aplica para SMS)
```

WhatsApp usa `wa_template_id` + `body_vars` + `language_code`. SMS usa `sms_body`. El resto de la campaña (nombre, segmento, scheduling, estados) es compartido.

### Tabla `message_log` — campo `channel`
```
channel = "whatsapp" | "sms"
```
Mismo flujo de estados: pending → sent → delivered → failed/undelivered.

---

## ⚠️ Formato de número — E.164 CON `+` (bug encontrado en prod)

El gateway (SMS Gateway for Android / capcom6) **rechaza el envío si el número no lleva `+`**.
- En BD los contactos viven como `52XXXXXXXXXX` (12 dígitos, **sin** `+`) — así los normaliza `Contact::normalizePhone()`.
- El gateway exige **E.164 con prefijo**: `+52XXXXXXXXXX`. Sin el `+` responde "invalid phone number" y el panel muestra *"El gateway rechazó el envío"*.
- **Fix aplicado**: `SmsGatewayClient::toE164()` antepone el `+` en el único punto de salida HTTP. El operador **nunca** escribe el `+` ni el código de país — el sistema lo agrega (`normalizePhone` pone el `52`, el cliente pone el `+`).
- **Regla para nuevo código**: cualquier envío al gateway pasa por `SmsGatewayClient::send()`, que ya normaliza. Nunca mandar `phoneNumbers` crudos desde otro lado. Si algún día se agrega otro proveedor SMS, revisar si también exige `+` (WhatsApp/Meta NO lo exige — ahí el `wa_id` va sin `+`).

---

## Manejo de errores Twilio — reglas de auto-protección

### Opt-out automático (error 21610)
```
SI Twilio retorna error 21610 → marcar contact.sms_opt_out = true
```
Twilio maneja opt-out automáticamente: si el usuario responde STOP, STOPALL, UNSUBSCRIBE, CANCEL, END o QUIT, Twilio lo bloquea a nivel carrier. El error 21610 nos confirma el bloqueo. No cobran por el intento.

### Mensaje bloqueado (error 30004)
```
SI StatusCallback = "undelivered" + error 30004 → marcar contacto como sms_blocked
```
Causas: DND, carrier filtró el mensaje, número no recibe SMS (línea fija), dispositivo apagado prolongado.

### Mensaje fallido
```
SI StatusCallback = "failed" → marcar número como sms_invalid
```
Causas: número no existe, cuenta suspendida, error de formato.

### Auto-blacklist por rebotes consecutivos
```
Después de 3 "undelivered" consecutivos al mismo contacto → auto-blacklist para SMS
```
No bloquear WhatsApp por rebotes de SMS. Cada canal tiene su propio contador de rebotes.

### Tabla de errores Twilio frecuentes

| Error | Status | Significado | Acción |
|---|---|---|---|
| 21610 | failed | Recipient unsubscribed (STOP) | `sms_opt_out = true`, nunca más enviar SMS |
| 30004 | undelivered | Message blocked | Incrementar contador rebotes, blacklist a los 3 |
| 30003 | undelivered | Unreachable destination | Reintentar 1 vez, luego incrementar contador |
| 30005 | undelivered | Unknown destination | `sms_invalid = true`, número no existe |
| 30006 | undelivered | Landline or unreachable | `sms_invalid = true`, es línea fija |
| 30007 | undelivered | Carrier violation | Revisar contenido del mensaje, posible filtro anti-spam |
| 30008 | undelivered | Unknown error | Reintentar 1 vez, si persiste loguear para revisión manual |

---

## Horarios de envío — diferencia entre canales

```
WhatsApp → Horario FORZADO L-V 7AM-10PM CST (ley LFPDPPP + políticas Meta)
SMS      → Sin restricción de horario en el sistema. El CLIENTE elige cuándo enviar.
```

### ¿Por qué SMS no tiene horario forzado?
México NO tiene restricción legal de horario específica para SMS marketing. La LFPDPPP y la LFTR regulan consentimiento y el REPEP, pero no establecen horario fijo para SMS (a diferencia de llamadas telefónicas). Las guías de "mejores prácticas" son recomendaciones, no ley.

### Advertencia en el panel
Si el cliente programa un envío SMS entre 11PM y 7AM, mostrar advertencia:
```
"⚠️ Enviar entre 11PM-7AM puede generar más bajas y filtrado por operadoras"
```
Es advertencia, no bloqueo. El cliente decide.

---

## Cumplimiento legal SMS en México

> **Actualización legal (verificado 2026):** la **nueva LFPDPPP** se publicó el **20-mar-2025** y
> entró en vigor el **21-mar-2025**. La supervisión y sanciones pasaron a la **Secretaría
> Anticorrupción y Buen Gobierno** (desapareció el INAI). Exige consentimiento para marketing y que
> el titular pueda **revocar su consentimiento en cualquier momento** (el responsable DEBE dar el
> medio para revocar). **Conclusión:** el **opt-out (STOP/BAJA) es obligatorio y NO se quita**,
> aunque el cliente sea blando con SMS — la multa la carga el cliente (responsable de los datos), y
> es el propósito del sistema (a prueba de errores del cliente). No somos abogados; el cliente debe
> confirmarlo con su asesor legal.

### Obligatorio
1. **Consentimiento previo** del receptor (LFPDPPP 2025) — el cliente debe demostrar que obtuvo autorización
2. **REPEP** (Registro Público para Evitar Publicidad) — si un número está en el REPEP, tienes 30 días para dejar de enviarle. Verificar periódicamente
3. **Opción de baja en cada mensaje** — incluir al final: "Responde STOP para no recibir más"
4. **Aviso de privacidad** — el aviso de privacidad del cliente debe mencionar que se enviarán SMS comerciales
5. **Contenido prohibido** — no enviar contenido político, religioso, engañoso o que promueva actividades ilegales

### Manejo de números SMS diferenciado (decisión del cliente, aprobado — IMPLEMENTADO)
El cliente es **blando con SMS**: usa SIM propia barata, NO le interesa cuidar la reputación del número.
Por eso el manejo de números SMS se separa del de WhatsApp:
- **Opt-out (STOP) → bloqueo permanente de SMS**: se queda (legal, ver arriba). No negociable.
- **Auto-blacklist por rebotes → configurable, apagado por default** (`Setting sms_auto_blacklist_bounces = 0`).
  El contador de rebotes sigue (para reporte) pero NO bloquea salvo umbral > 0. "No suma fallas."
  Editable en Configuración (solo superadmin) vía `GET/PUT /api/settings/sms-auto-blacklist`.
  La lógica vive en `Contact::registerSmsBounce()`.
- WhatsApp mantiene su bloqueo estricto por rebotes (protege el tier de Meta — ahí sí importa).
- Los rebotes/fallos SMS **nunca** afectan el canal WhatsApp (ya es por canal).

### No obligatorio pero recomendado
- Enviar en horarios laborales (mayor tasa de lectura)
- Identificar al remitente en el mensaje ("Prestamaz te informa:")
- Limitar frecuencia (no más de 2-3 SMS por contacto por semana)

---

## Protección anti-duplicado

> **DECISIÓN DEL CLIENTE (implementada):** dedup diario, cooldown **y snooze** son **POR CANAL**,
> no cross-channel. WhatsApp lleva su propio conteo y SMS el suyo — un contacto que recibió
> WhatsApp hoy **sí** puede recibir un SMS hoy (y viceversa). El diseño original era
> cross-channel; el cliente pidió separarlos para poder impactar por ambos canales.
> Implementado con filtro `->where('channel', ...)` en los dedup/cooldown de ambos jobs
> (`SendWhatsAppMessage`, `SendSmsMessage`).
>
> **Snooze POR CANAL (solo WhatsApp):** el "No por ahora" nace de un botón de plantilla de
> WhatsApp (SMS no tiene botones) y pausa **solo WhatsApp**. `SendSmsMessage` **NO** checa
> `isSnoozeActive()`, y en la entregabilidad (`ContactController`) el snooze NO entra al eje SMS.
> Un contacto en snooze de WhatsApp sigue recibiendo SMS. La columna `snoozed_until` es, en la
> práctica, el "snooze de WhatsApp".
>
> **Lo que SIGUE siendo cross-channel** (no se tocó): el **opt-out** y el **blacklist**.
> Una baja (STOP/BAJA) o un `status = opted_out` bloquea AMBOS canales — es regla legal/seguridad.

### Dedup por canal
Antes de enviar por un canal, verificar solo ese canal:
```
SMS:      ¿recibió un SMS hoy / dentro del cooldown SMS? → si sí, NO enviar SMS
WhatsApp: ¿recibió un WA hoy / dentro del cooldown WA?   → si sí, NO enviar WA
```

**Cooldown**: `Setting::get('cooldown_days', 30)`, mínimo 7. Aplica igual a ambos canales
pero cada uno cuenta el suyo por separado.

### Advertencia de duplicado
Si una campaña WA y una SMS apuntan al mismo segmento en el mismo día:
```
"⚠️ Estos contactos ya tienen una campaña WA programada hoy. ¿Seguro?"
```
Advertencia, no bloqueo.

### Reglas absolutas
- **Nunca** enviar el mismo mensaje por ambos canales al mismo contacto en la misma campaña
- **Nunca** usar SMS como fallback automático de WhatsApp sin consentimiento explícito
- Si la calidad del número WA baja a "Low", el circuit breaker detiene WA pero SMS sigue normal

---

## Webhook de status (StatusCallback)

Twilio envía webhooks con el estado de cada mensaje. Configurar `StatusCallback` URL en cada envío.

### Estados posibles
```
queued → sent → delivered
                ↘ undelivered (con error code)
         ↘ failed (con error code)
```

### Implementación
- Crear ruta `POST /api/sms/webhook/status` para recibir StatusCallback
- Actualizar `message_log.status` según el estado recibido
- Si `undelivered` o `failed`, aplicar reglas de auto-protección (ver sección de errores arriba)
- Validar que el webhook viene de Twilio (verificar firma o IP whitelist)

---

## Desarrollo y testing

### Trial para desarrollo
- Crear cuenta Twilio trial (gratis, sin tarjeta)
- 50 SMS/día a números verificados
- Los SMS llegan con prefijo "Sent from a Twilio Trial account"
- Suficiente para desarrollo y pruebas manuales

### Tests automatizados (PHPUnit)
Nunca tocar la API real en tests. Usar `Http::fake()`:
```php
Http::fake([
    'api.twilio.com/*' => Http::response([
        'sid' => 'SM' . Str::random(32),
        'status' => 'queued',
    ], 201),
]);
```

### Mock de StatusCallback en tests
Simular webhook de status:
```php
$this->postJson('/api/sms/webhook/status', [
    'MessageSid' => 'SMtest123',
    'MessageStatus' => 'delivered',
]);
```

---

## Decisiones pendientes

- [ ] Confirmar si el cliente necesita CFDI específico por SMS (determina Twilio vs SMS Masivos)
- [ ] Definir ventana de exclusión inicial (recomendación: 24h)
- [ ] Definir límite de SMS por contacto por semana (recomendación: 3)
- [ ] Verificar si el cliente tiene aviso de privacidad que incluya SMS comerciales
