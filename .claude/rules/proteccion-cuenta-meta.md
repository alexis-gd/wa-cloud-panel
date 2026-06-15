# Regla: Protección de cuenta Meta — PRIORIDAD MÁXIMA

> Esta regla aplica a Alexis Y a Claude. Antes de cualquier acción relacionada con
> envíos, plantillas, tokens o configuración de Meta, verificar esta lista.
> **Si hay duda, NO proceder. Preguntar primero.**

---

## 🛑 STOP — Detener y preguntar antes de hacer esto

| Acción | Riesgo | Qué hacer en su lugar |
|---|---|---|
| Enviar a un número que NO está en la lista de destinatarios de prueba | Ban sandbox inmediato | Agregar el número primero en developers.facebook.com |
| Enviar con una plantilla NO aprobada | Rechazo + penalización | Esperar aprobación, solo usar `status = approved` |
| Enviar más de ~1,000 mensajes/día en Tier 1 | Degradación de calidad | Respetar warm-up automático del sistema |
| Cambiar manualmente el límite de warm-up | Bypass del sistema de protección | No hay override — el sistema decide |
| Submitir la misma plantilla rechazada sin modificarla | Señal de spam para Meta | Modificar contenido o esperar 24h |
| Hacer llamadas directas a `graph.facebook.com` fuera de `WhatsAppClient::post()` | Sin rate limiting ni logging | Siempre pasar por el cliente centralizado |
| Guardar o loguear el token completo | Exposición de credenciales | Loguear máximo últimos 4 caracteres |
| Enviar fuera de 9AM-10PM CST L-V | Posible reporte de spam | El scheduler lo impide — no bypasear |
| Ignorar un opt-out (STOP/NO/BAJA/CANCELAR) | Ban + violación de políticas | Marcar inmediatamente, nunca más enviar |
| Usar el número oficial del cliente para campañas | Riesgo sobre número principal | Solo usar número dedicado de API |

---

## ✅ Antes de cada sesión de desarrollo que toque envíos

- [ ] ¿El número destino está en la lista de prueba de Meta?
- [ ] ¿La plantilla que voy a usar tiene `status = approved`?
- [ ] ¿El token en BD es válido? (si lleva más de 20h, renovar)
- [ ] ¿El código pasa por `WhatsAppClient::post()` y no hace Http:: directo?

---

## Señales de alerta en los logs (revisar antes de continuar)

- `error_code: 131026` → número no existe en WhatsApp (marcar inválido, no reintentar)
- `error_code: 131048` → spam rate limit hit — **PARAR envíos mínimo 1 hora**
- `error_code: 131049` → quality rate limit hit — **PARAR envíos mínimo 1 hora** (igual que 131048 pero por calidad del número, llega vía webhook de delivery, no por respuesta directa del API)
- `error_code: 368` → cuenta temporalmente bloqueada — **PARAR TODO, revisar Business Manager**
- `error_code: 467` → token expirado — renovar antes de continuar
- `error_code: 470` → plantilla rechazada/no aprobada — revisar status en Meta

---

## Contexto actual (cuenta de pruebas — Alexis)

- Cuenta: sandbox, NO verificada — límites más estrictos
- Solo puede enviar a números registrados como destinatarios de prueba
- Plantillas en revisión no afectan el score — solo las que son rechazadas repetidamente
- Esta cuenta se usará hasta Stage 3; la del cliente entra en producción

---

## Para Claude: checklist antes de generar código de envío

1. ¿El código usa `WhatsAppClient::post()`? Si no → reescribir
2. ¿Hay algún loop de envío sin rate limiting? → Agregar delay o queue
3. ¿Se están logueando tokens? → Eliminar o truncar a últimos 4 chars
4. ¿El job tiene retry sin límite? → Máximo 3 intentos con backoff exponencial
5. ¿Se verifica opt-out antes de enviar? → Si no → agregar check
