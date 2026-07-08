# SMS — Arquitectura multicanal y guía de integración

> Documento técnico de referencia para el canal SMS dentro de wa-cloud-panel.
> Para reglas y políticas de SMS, ver `.claude/rules/contexto-sms.md`
> Para políticas de WhatsApp/Meta, ver `.claude/rules/contexto-meta-whatsapp.md`
>
> ⚠️ **Proveedor real: SIM propia con gateway self-host** (SMS Gateway for Android / capcom6),
> NO Twilio. Twilio se evaluó pero no se eligió. La arquitectura multicanal de este doc (canales
> independientes, contactos/campañas compartidos, `channel` en `message_log`) **sí aplica** — solo
> cambia el "cómo se envía": en vez de la API de Twilio, el job `SendSmsMessage` llama a
> `SmsGatewayClient` (gateway capcom6). Setup real: [`guia-sms-gateway-setup.md`](guia-sms-gateway-setup.md).

---

## Visión general

wa-cloud-panel es un sistema **multicanal**: WhatsApp + SMS desde un solo panel. Ambos canales comparten infraestructura (contactos, campañas, métricas, panel) pero son completamente independientes en ejecución (números, credenciales, queues, reputación).

---

## Arquitectura multicanal

```
Panel del cliente
       │
       ▼
CampaignController ──── channel: whatsapp | sms
       │                         │
       ├── WhatsAppClient        ├── SmsClient
       │   (Meta Cloud API)      │   (Twilio API)
       │                         │
       ├── SendWhatsAppMessage   ├── SendSmsMessage
       │   (Job independiente)   │   (Job independiente)
       │                         │
       └──────── message_log ────┘
                 channel: whatsapp | sms
```

### Principio de aislamiento
- Si SMS se atora en la queue, WhatsApp sigue enviando sin afectación
- Si la calidad del número WA baja, el circuit breaker detiene WA pero SMS sigue
- Si Twilio tiene downtime, WhatsApp no se entera
- Credenciales, números y reputación son mundos separados

---

## Comparativo canales

| Aspecto | WhatsApp | SMS |
|---|---|---|
| Proveedor | Meta Cloud API directa | Twilio (o SMS Masivos) |
| Contenido | Plantillas aprobadas por Meta | Texto plano (160 chars/segmento) |
| Horario | FORZADO L-V 7AM-10PM CST | Sin restricción legal (advertencia nocturna) |
| Opt-out | STOP/NO/BAJA → webhook Meta | STOP/CANCEL/END → Twilio error 21610 |
| Warm-up | Obligatorio (500 → 10K → 100K/día) | No aplica, sin límite de warm-up |
| Delivery report | Meta webhook: sent/delivered/read/failed | Twilio StatusCallback: queued/sent/delivered/undelivered/failed |
| Lectura | Sí (status "read") | No detectable |
| Costo/msg | ~$0.04 USD (marketing) | ~$0.01 USD (Twilio) / ~$0.47 MXN (SMS Masivos) |
| Riesgo | Suspensión permanente de WABA | Sin riesgo de cuenta, solo filtrado carrier |

---

## Flujo de creación de campaña multicanal

### Paso 1: Cliente elige canal
```
[WhatsApp]  o  [SMS]
```

### Paso 2: Contenido según canal
- **WhatsApp**: Selector de plantillas aprobadas → variables → preview
- **SMS**: Textarea de texto plano + contador de caracteres (160/320/480) + aviso obligatorio "Responde STOP para no recibir más" al final

### Paso 3: Selección de contactos (compartido)
- Mismo segmento/filtro para ambos canales
- **Filtro automático anti-duplicado**: excluir contactos impactados en la ventana de exclusión (24h default)
- Mostrar: "245 contactos seleccionados, 12 excluidos (recibieron [canal] ayer)"

### Paso 4: Scheduling (diferenciado)
- **WhatsApp**: solo permite horario L-V 7AM-10PM CST
- **SMS**: cualquier horario, con advertencia si es 11PM-7AM

### Paso 5: Envío y monitoreo (compartido)
- Mismo dashboard de métricas
- Misma tabla `message_log` filtrable por canal

---

## Protección anti-duplicado — POR CANAL (decisión del cliente, implementada)

> ⚠️ **Actualizado (v0.27):** el diseño original era cross-channel; el cliente pidió separarlo
> para impactar por ambos canales. La fuente de verdad de esta lógica es
> [`.claude/rules/contexto-sms.md`](../.claude/rules/contexto-sms.md).

Qué es por canal y qué es cross-channel:

| Regla | Alcance | Nota |
|---|---|---|
| **Dedup diario** (1 msj/día por contacto) | **por canal** | un WhatsApp hoy NO frena el SMS de hoy |
| **Cooldown** (`Setting cooldown_days`, mín 7) | **por canal** | cada canal cuenta el suyo |
| **Snooze** ("No por ahora") | **por canal (solo WhatsApp)** | nace de un botón WA; SMS no lo respeta |
| **Opt-out** (STOP/BAJA / `status=opted_out`) | **cross-channel** | una baja bloquea AMBOS (legal) |
| **Blacklist** por rebotes | **cross-channel** para opt-out, propio de SMS para rebotes | ver `registerSmsBounce()` |

Cada job filtra su propio canal: `->where('channel', 'whatsapp'|'sms')` en `SendWhatsAppMessage`
y `SendSmsMessage`. El snooze se checa **solo** en el job WhatsApp.

### Reglas absolutas
1. Nunca enviar el mismo mensaje por ambos canales al mismo contacto en la misma campaña
2. Nunca usar SMS como fallback automático de WA sin consentimiento
3. Opt-out (baja) → bloqueo en AMBOS canales (no negociable, legal)

---

## SmsClient.php — reglas de implementación

Siguiendo el patrón de `WhatsAppClient.php`:
- `app/Services/Sms/SmsClient.php` — **ÚNICO** lugar que hace HTTP a Twilio
- Nunca `Http::` ni curl directo a Twilio en controllers/jobs
- Todo envío crea registro en `message_log` ANTES de llamar a la API
- Credenciales en `.env` → `TWILIO_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM_NUMBER`
- En producción, las credenciales se leen de tabla `sms_providers` (similar a `phone_numbers` para WA)

---

## Delivery reports SMS

### Webhook de status
Ruta: `POST /api/sms/webhook/status`

Twilio envía:
```json
{
    "MessageSid": "SM...",
    "MessageStatus": "delivered",
    "ErrorCode": null
}
```

Estados finales: `delivered`, `undelivered` (con ErrorCode), `failed` (con ErrorCode).

### Mapeo de estados a acciones

| Estado Twilio | Error | Acción en BD |
|---|---|---|
| `delivered` | — | `message_log.status = delivered` |
| `undelivered` | 30004 | `sms_blocked = true`, incrementar contador rebotes |
| `undelivered` | 30003 | Reintentar 1 vez, luego incrementar contador |
| `undelivered` | 30005 | `sms_invalid = true` (número no existe) |
| `undelivered` | 30006 | `sms_invalid = true` (línea fija) |
| `undelivered` | 30007 | Revisar contenido, posible filtro anti-spam |
| `failed` | 21610 | `sms_opt_out = true` (usuario envió STOP) |
| `failed` | cualquier | `message_log.status = failed`, loguear error |

### Auto-blacklist
Después de **3 undelivered consecutivos** al mismo contacto → `contact.sms_blacklisted = true`.
No afecta WhatsApp. Cada canal tiene su propio contador.

---

## Cumplimiento legal SMS México

### Obligatorio
1. Consentimiento previo (LFPDPPP 2025)
2. Respetar REPEP (Registro Público para Evitar Publicidad)
3. Incluir "Responde STOP para no recibir más" en cada SMS
4. Aviso de privacidad del cliente debe mencionar SMS comerciales
5. No contenido político, religioso, engañoso ni ilegal

### Diferencia clave vs WhatsApp
- WhatsApp tiene horario legal forzado → el sistema lo impone
- SMS NO tiene horario legal → el sistema permite cualquier hora, con advertencia nocturna
- WhatsApp requiere plantillas aprobadas por Meta → el sistema solo muestra aprobadas
- SMS no requiere aprobación de plantillas → texto libre, pero con footer de opt-out obligatorio
