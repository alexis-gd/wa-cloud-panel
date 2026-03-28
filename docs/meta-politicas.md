# Políticas de Meta — WhatsApp Cloud API

## Warm-up obligatorio

El número nuevo empieza con capacidad limitada y sube gradualmente si la calidad es buena.

| Semana | Límite diario | Condición para subir |
|---|---|---|
| 1 | 500 mensajes/día | Calidad High o Medium |
| 2-3 | 10,000 mensajes/día (Tier 2) | Calidad sostenida |
| 3-4+ | 100,000 mensajes/día (Tier 3) | Calidad sostenida |

**El sistema impone estos límites automáticamente. El cliente NO puede subirlos manualmente.**

## Precios PMP (desde julio 2025)

- Marketing: ~$0.04 USD por mensaje entregado
- Utility: ~$0.01 USD por mensaje entregado
- Authentication: precio variable
- Los mensajes no entregados no se cobran (importante para la calidad del número)

*Nota: Los precios PMP de Meta varían por país destino. Verificar tabla actualizada en developers.facebook.com.*

## Límites (desde octubre 2025)

- Los límites son por **Business Portfolio**, no por número individual.
- 2-4 números en el mismo Business Portfolio comparten el tier.

## Calidad del número

- **High**: Calidad óptima, puede subir de tier.
- **Medium**: Aceptable, mantiene tier actual.
- **Low**: Degradación de tier. Activa circuit breaker en el sistema.

La calidad baja cuando:
- Usuarios bloquean el número
- Muchos mensajes no leídos
- Reportes de spam
- Mensajes a números que no tienen WhatsApp

## Plantillas

- Categorías: **Marketing**, **Utility**, **Authentication**
- Aprobación: 1-48 horas tras enviar a Meta
- Si se rechaza: se puede corregir y reenviar
- Causas de rechazo: spam, sin opt-out claro, promesas exageradas, contenido engañoso
- **El sistema solo muestra plantillas con `status = 'approved'`**

## Opt-out (obligatorio)

- Por política de Meta: toda plantilla Marketing DEBE incluir opt-out
- Por LFPDPPP mexicana: igual
- Palabras clave que activan opt-out automático: **STOP, NO, BAJA, CANCELAR**
- El contacto se marca en BD como `opted_out = true` — **nunca más se le envía**
- No se elimina el registro (auditoría)

## Horario legal (LFPDPPP México)

- Horario permitido: Lunes a Viernes, 7:00 AM – 10:00 PM CST
- El scheduler de Laravel solo ejecuta envíos en este horario
- Sin override manual permitido al cliente

## Causas comunes de suspensión

1. Enviar sin consentimiento previo del destinatario
2. Ignorar opt-out
3. Warm-up agresivo (subir límites antes de tiempo)
4. Contenido engañoso o promesas falsas
5. Auto-responder a cualquier mensaje entrante (patrón de spam)

## Token de producción

- **Token temporal** (developers.facebook.com): dura ~24h, se invalida al cerrar sesión. Solo para desarrollo.
- **System User Token**: no expira. Obligatorio antes de producción.
- Cómo crear: `business.facebook.com` → Configuración del negocio → Usuarios del sistema

## Usernames como identificadores (deadline junio 2026)

Meta está migrando a soportar **usernames** además de números de teléfono como identificadores.
- El campo `wa_id` / `to` en contactos y mensajes debe soportar ambos formatos
- Validar formato condicionalmente: si es número → E.164; si es username → no aplicar regex
- Ver detalle en memory: `project_meta_usernames.md`
