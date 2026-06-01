# Contexto Twilio SMS — Reglas, políticas y decisiones

> Canal complementario a WhatsApp. La prioridad #1 sigue siendo proteger la cuenta WABA.
> SMS es un canal independiente: si SMS tiene problemas, WhatsApp no se entera y viceversa.

---

## Proveedor elegido: Twilio

- **API**: REST API + PHP SDK oficial (`twilio/sdk`)
- **Pricing México**: ~$0.0075 USD/msg + ~$0.002-0.005 USD carrier fees = ~$0.01 USD/msg (~$0.20 MXN)
- **Trial gratuito**: Sí. Crédito inicial, 50 msgs/día, solo a números verificados, prefijo "Sent from Twilio Trial"
- **Facturación**: USD, sin CFDI mexicano. Nosotros absorbemos costo y facturamos al cliente como "servicio de mensajería"
- **Alternativa mexicana**: SMS Masivos (smsmasivos.com.mx) — CFDI 4.0, $0.47 MXN/msg público (cotización especial 200K+)
- **Decisión**: Twilio para desarrollo e integración (mejor API, mejor docs, webhooks de status). Evaluar SMS Masivos solo si el cliente exige CFDI específico por SMS

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

### Obligatorio
1. **Consentimiento previo** del receptor (LFPDPPP 2025) — el cliente debe demostrar que obtuvo autorización
2. **REPEP** (Registro Público para Evitar Publicidad) — si un número está en el REPEP, tienes 30 días para dejar de enviarle. Verificar periódicamente
3. **Opción de baja en cada mensaje** — incluir al final: "Responde STOP para no recibir más"
4. **Aviso de privacidad** — el aviso de privacidad del cliente debe mencionar que se enviarán SMS comerciales
5. **Contenido prohibido** — no enviar contenido político, religioso, engañoso o que promueva actividades ilegales

### No obligatorio pero recomendado
- Enviar en horarios laborales (mayor tasa de lectura)
- Identificar al remitente en el mensaje ("Prestamaz te informa:")
- Limitar frecuencia (no más de 2-3 SMS por contacto por semana)

---

## Protección anti-duplicado entre canales

### Ventana de exclusión por contacto (cooldown cross-channel)
Antes de enviar a un contacto, verificar:
```
¿Este contacto recibió CUALQUIER mensaje (WA o SMS) en las últimas X horas?
Si sí → NO enviar. El contacto ya fue impactado.
```

**Config recomendada**: ventana de 24 horas por defecto, configurable (12h, 24h, 48h, 72h).

### Al crear campaña — filtro automático
El sistema excluye automáticamente contactos impactados en la ventana de exclusión.
El cliente ve: "245 contactos seleccionados, 12 excluidos (recibieron WhatsApp ayer)"

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
