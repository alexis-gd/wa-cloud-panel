# Reglas de seguridad

## Inquebrantables (nunca se modifican)

1. **Todo HTTP a Meta pasa por `WhatsAppClient::post()`** — nunca `Http::` ni `curl` directo en controllers, jobs o servicios.
2. **Tokens en BD con `cast: 'encrypted'`** — AES-256 vía APP_KEY. Nunca guardar en texto plano.
3. **`.env` en `.gitignore` SIEMPRE** — nunca commitear tokens, secrets ni API keys.
4. **WA_WEBHOOK_VERIFY_TOKEN es secreto separado** del bearer token de Meta.
5. **Validar `X-Hub-Signature-256`** en cada webhook POST contra `WA_APP_SECRET`.
6. **Rate limiting en todas las rutas API** — `throttle:60,1` mínimo.

## Protección del cliente

7. El cliente **no ve ni edita** tokens, Phone IDs, ni configuración de Meta.
8. El selector de plantillas **solo muestra aprobadas** (`status = 'approved'`). Nunca permitir escribir nombres a mano.
9. **Warm-up automático** — el sistema impone límites diarios, el cliente no puede subirlos.
10. **Opt-out inmediato** — si responden STOP/NO/BAJA/CANCELAR, bloquear y nunca más enviar.
11. **Horario forzado** — el scheduler solo corre L-V 7AM-10PM CST. Sin override manual.

## Datos sensibles

12. Nunca loguear tokens completos — máximo los últimos 4 caracteres.
13. Nunca exponer IDs internos de Meta en responses al frontend.
14. Contactos con opt-out se marcan en BD, no se eliminan (auditoría).
