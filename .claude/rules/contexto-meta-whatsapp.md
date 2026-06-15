# Contexto Meta / WhatsApp Cloud API — Conocimiento acumulado

Este archivo viaja con el repo. Contiene decisiones, lecciones aprendidas y reglas
de negocio descubiertas durante el desarrollo. Leerlo antes de tocar cualquier cosa
relacionada con Meta, envíos o plantillas.

---

## Arquitectura de números — Decisión de diseño

- **Múltiples números = estrategia planeada**, no parche
- Cada número tiene warm-up controlado por el sistema (no configurable por el cliente)
- El balanceo entre números es automático según tier y calidad de cada uno
- El cliente NO elige qué número envía — el sistema decide
- Nunca usar el número oficial del cliente para campañas — solo para contacto directo
- Si un número baja de calidad: circuit breaker lo pausa, no se elimina

## Horario de envíos — Fijo e inamovible

- Ventana: **9:00 AM – 10:00 PM `America/Mexico_City` (CST / UTC-6)**
- Cubre todas las zonas horarias de México de forma segura:
  - Baja California (PST/UTC-8): recibe entre 7AM–8PM ✅
  - Sonora/Sinaloa (MST/UTC-7): recibe entre 8AM–9PM ✅
  - Resto de México (CST/UTC-6): recibe entre 9AM–10PM ✅
- Sin detección por LADA — ventana única es suficiente
- Solo L-V (lunes a viernes)
- Sin override manual por el cliente — el scheduler es forzado por el sistema

## Tiers de Meta y warm-up

| Tier | Límite diario | Cómo subir |
|---|---|---|
| 1 (sandbox/inicio) | 250 conversaciones/día | Verificar negocio en Meta |
| 2 | 1,000 conversaciones/día | Automático al llegar a 1k en Tier 1 con buena calidad |
| 3 | 10,000 conversaciones/día | Automático al llegar a 10k en Tier 2 |
| 4 | Ilimitado | Automático |

- **Warm-up gradual es obligatorio** — subir directo a miles de mensajes = ban
- El sistema impone límites diarios por tier automáticamente, el cliente no los ve ni edita

**Cambio de plataforma (2025):** Meta movió los límites de por-número a por-cuenta (Business Portfolio/WABA).
- El tier aplica a la cuenta entera, no a cada número individualmente.
- Múltiples números bajo la misma WABA **no multiplican el límite total** — comparten el techo de la cuenta.
- Se pueden agregar números sin límite de cantidad, pero el volumen sigue siendo el del tier de la cuenta.
- Business Verification es el requisito para desbloquear tiers superiores a 1.
- Verificar en: business.facebook.com → Configuración → Información del negocio → campo "Verificación del negocio".
- Tener página de FB o cuenta personal verificada NO equivale a Business Verification.

## Token de acceso — Reglas críticas

- **Token actual**: System User Token **sin expiración** — ya configurado y en uso
  - System User: `waclouddev` · App: `wa-api-test`
  - Permisos: `whatsapp_business_messaging` + `whatsapp_business_management`
  - WABA asignada con Control total
- **Token temporal** (ya no usamos, solo referencia): duraba ~24h, se invalidaba al cerrar sesión en FB (error 467)
- El token vive en `phone_numbers.token` en BD, cifrado con `cast: 'encrypted'` (AES-256)
- El `.env WA_TOKEN` NO afecta los envíos — solo es referencia
- Nunca loguear token completo — máximo últimos 4 caracteres
- Actualizar token si fuera necesario: pantalla Configuración del panel, o `php artisan wa:update-token TOKEN`

## Cuenta del cliente vs cuenta de desarrollo

- **Dev/Staging (hasta Stage 3)**: cuenta Meta del dev con número sandbox `+1 555-146-8965`
- **Producción (Stage 3)**: cuenta Meta del cliente con número dedicado verificado
- La cuenta del cliente necesita Business Verification antes de producción (3–10 días hábiles)
- System User Token del cliente se crea junto con el onboarding de producción
- Número para campañas: SIM dedicada nueva (Telcel/AT&T), nunca el número oficial del cliente

## Plantillas — Reglas y aprendizajes

- Someter plantillas a revisión **no afecta el score** — solo los rechazos repetidos del mismo contenido
- Categoría siempre: **Marketing** (no Utility — eso es para transaccionales)
- Idioma: `es_MX`
- **CAT promedio informativo es OBLIGATORIO por ley en México (CONDUSEF)** para publicidad financiera
  - Sin CAT, Meta puede rechazar la plantilla de servicios financieros
  - Formato: `CAT promedio informativo X% sin IVA`
- Siempre incluir opt-out — preferiblemente como botón ("No, gracias") no solo texto
- Solo mostrar plantillas con `status = approved` en el panel — nunca input libre
- El panel nunca permite escribir nombre de plantilla a mano

## Validación de contactos al importar

- Flujo: formato E.164 → deduplicar en BD → cruzar opt-out → normalizar México (+52) → aceptar/rechazar
- NO usar API de Meta para pre-validar existencia (costo + riesgo de scraping)
- Números inexistentes se detectan en envío: `error_code 131026` → marcar inválido, no reintentar
- Formato México: Meta acepta `529231311146` y normaliza a `5219231311146` (wa_id)
- Mostrar reporte al importar: aceptados / duplicados / formato inválido

## Códigos de error Meta — Qué hacer

| Código | Significado | Acción |
|---|---|---|
| `131026` | Número no existe en WhatsApp | Marcar inválido, no reintentar |
| `131048` | Spam rate limit | **PARAR envíos mínimo 1 hora** |
| `131049` | Quality rate limit (llega por webhook de delivery, no por respuesta API directa) | **PARAR envíos mínimo 1 hora** — `WebhookController` pausa el número igual que 131048 |
| `368` | Cuenta bloqueada temporalmente | **PARAR TODO, revisar Business Manager** |
| `467` | Token expirado | Renovar token antes de continuar |
| `470` | Plantilla no aprobada | Revisar status en Meta |

## Opt-out — Reglas inquebrantables

- Palabras que disparan opt-out automático: STOP, NO, BAJA, CANCELAR, NO GRACIAS
- Opt-out es inmediato e irreversible por el cliente
- Contactos con opt-out se marcan en BD, **no se eliminan** (auditoría)
- Si alguien responde opt-out, nunca más se le envía — el sistema lo bloquea antes del envío

## Causa del baneo anterior (v1) — No repetir

- Usaba `WA_TOKEN` como `hub_verify_token` (misma variable — bug de seguridad)
- Auto-responder genérico a CUALQUIER mensaje entrante (patrón de spam para Meta)
- Sin validación `X-Hub-Signature-256` en webhook POST
- Token hardcodeado en readme.md (exposición pública)
- Sin rate limiting ni warm-up

## Referencia competencia — DiDi Préstamos

- DiDi quema números por spam masivo sin segmentación → rota 7+ números
- Nosotros usamos múltiples números como arquitectura planeada (balanceo), no como parche
- Lo bueno de DiDi: botones opt-out integrados, CAT visible, imágenes de marca, montos concretos
- Lo malo de DiDi: bases frías sin segmentación, frecuencia excesiva, números quemados

## Alertas Meta a ignorar (no urgentes)

- "API de mensajes de marketing para WhatsApp" — API diferente, más cara, no la usamos
- "Eventos automáticos" — analytics de Meta, no necesario
- "Libreta de contactos" — para chat entrante, Stage 3

## Cambios críticos Meta pendientes

- **Usernames como identificadores (deadline junio 2026)**: el campo `wa_id` y upload de contactos
  debe soportar usernames (`@username`) además de números E.164

---

## Warm-up semanal — cronograma orientativo

| Semana | Límite diario aprox. | Condición para subir |
|---|---|---|
| 1 | 500 mensajes/día | Calidad High o Medium sostenida |
| 2–3 | 10,000 mensajes/día (Tier 2) | Calidad sostenida |
| 3–4+ | 100,000 mensajes/día (Tier 3) | Calidad sostenida |

Los límites exactos los impone Meta automáticamente. El sistema los refleja en `phone_numbers.daily_limit`.

## Precios PMP (vigentes desde julio 2025)

- **Marketing**: ~$0.04 USD por conversación entregada
- **Utility**: ~$0.01 USD por conversación entregada
- **Authentication**: precio variable por país
- Los mensajes no entregados **no se cobran** — la calidad del número impacta directamente en el costo
- Los límites son por **Business Portfolio**, no por número individual: 2–4 números en el mismo portfolio comparten el tier

*Verificar tabla actualizada en developers.facebook.com si los precios cambian.*

## Calidad del número — niveles y causas

| Nivel | Efecto | Causas típicas |
|---|---|---|
| **High** | Calidad óptima, puede subir de tier | — |
| **Medium** | Aceptable, mantiene tier actual | Algunos bloqueos o mensajes no leídos |
| **Low** | Degradación de tier, activa circuit breaker | Muchos bloqueos, reportes de spam, mensajes a números sin WhatsApp |

La calidad baja cuando: usuarios bloquean el número, mensajes masivos no leídos, reportes de spam, envíos a números inexistentes en WhatsApp.

## Causas comunes de rechazo de plantillas

- Contenido de spam o promesas exageradas
- Sin botón o texto de opt-out claro
- Contenido engañoso
- Mismo contenido rechazado y resometido sin cambios

## Causas comunes de suspensión de cuenta

1. Enviar sin consentimiento previo del destinatario
2. Ignorar respuestas de opt-out (STOP/NO/BAJA/CANCELAR)
3. Warm-up agresivo (subir límites antes de tiempo)
4. Contenido engañoso o promesas falsas en plantillas
5. Auto-responder a cualquier mensaje entrante (patrón de bot/spam)
