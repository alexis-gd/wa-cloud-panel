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

**Límite de mensajería - cómo lo leemos (implementado 2026-07-08):**
- El campo `messaging_limit_tier` está **DEPRECADO**. El nuevo es `whatsapp_business_manager_messaging_limit` (valores `TIER_250`, `2000`, `10000`, `100000`, `UNLIMITED`), pedible en phone/WABA/portfolio.
- El límite es **por Business Portfolio** (compartido por todos los números), vigente desde 7-oct-2025. Sube solo (~6h) si en 7d usaste >=50% del límite con calidad alta.
- **Lo leemos SIN webhook ni polling:** `SettingsController::phoneHealth()` ya hace un GET al phone number para el semáforo; le agregamos el campo `whatsapp_business_manager_messaging_limit` a ese mismo GET, lo persiste en `Setting wa_portfolio_daily_limit` (+ `wa_portfolio_limit_updated_at`) y lo devuelve. Se refresca cada que se abre/refresca el semáforo del Panel. (Se descartó el webhook `business_capability_update` para no depender de suscribir un campo en Meta.)
- El panel (Dashboard, semáforo) muestra "Límite de la cuenta (Meta)" desde ese dato.
- **Warm-up automático + freno (implementado 2026-07-08):** `App\Services\WhatsApp\PortfolioLimit::daily()` parsea el límite del portfolio (helper reusable). 
  - **Freno de cuenta:** `SendWhatsAppMessage` no envía si el total del día (todos los números) alcanzó el límite del portfolio → reencola para mañana. Nunca rebasa a Meta.
  - **Warm-up:** comando `wa:warmup-numbers` (scheduler diario 05:00 CST) sube el `daily_limit` de cada número activo/no-pausado que usó >=50% de su límite ayer (criterio Meta), duplicando, topado por el límite del portfolio. No rampa si el portfolio es desconocido.
  - **Warm-down por error duro:** `PhoneNumber::backOffDailyLimit()` (halve, piso `WARMUP_FLOOR`=250) se llama al pausar por calidad/spam (131048, 131064, 368) en `SendWhatsAppMessage` y `WebhookController`. Así un número que se degrada recula y re-calienta conservador tras la pausa (simetría del warm-up).
  - **Warm-down por calidad suave (implementado 2026-07-08):** `wa:warmup-numbers` lee el `quality_rating` de Meta por número (vía `WhatsAppClient::get`, campo `quality_rating`) ANTES de decidir: **RED** -> `backOffDailyLimit()` (recula, no pausa - es señal suave sin error); **YELLOW** -> hold (ni sube ni baja); **GREEN/UNKNOWN/sin dato** -> warm-up normal. Tolerante: si Meta falla, trata como UNKNOWN (no penaliza por error transitorio). Cubre el hueco de una degradación que no dispara error de envío.
  - **Capacidad del Dashboard:** `dailySendCapacity()` = `min(portfolio, suma por número)`, ya no sobreestima.

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
- Siempre incluir la instrucción de baja EN EL TEXTO del cuerpo ("Responde STOP para dejar de recibir mensajes"). NO por botón: un botón "No, gracias" NO da de baja (los botón_reply no pasan por el check de opt-out; ver más abajo)
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
| `131026` | Número no existe / no acepta ToS / versión vieja | Marcar inválido, no reintentar |
| `131048` | Restricción de envíos del número (spam/bloqueos) | **PARAR envíos mínimo 1 hora** - `SendWhatsAppMessage` pausa el número 60 min |
| `131049` | Tope de marketing **POR USUARIO** (frecuencia del destinatario, suma de todas las empresas) - llega por webhook de delivery | **NO pausar el número.** Solo falla ese mensaje; los demás contactos siguen. Implementado: `Contact::holdWaMarketingFor24h()` pone `wa_marketing_hold_until = now()+24h` (Meta exige 24h, reintentar antes lo bloquea hasta 24h más). El job WA respeta el hold (`isWaMarketingHoldActive()`). Es aparte del enfriamiento (7-30d) y del Pospuesto |
| `131050` | El usuario se dio de baja de marketing **desde WhatsApp** (baja a nivel Meta, sin escribirnos texto) - llega por webhook de delivery | `WebhookController` marca `optOut('whatsapp_131050')` (cross-channel). Opcional futuro: webhook `user_preferences` para enterarse sin intento de envío |
| `131064` | Límite de la cuenta por infracciones de **categorización de plantillas** (afecta toda la WABA) | Pausar el número 60 min (`SendWhatsAppMessage` y `WebhookController`). Revisar categorías en Business Manager |
| `368` | Cuenta bloqueada temporalmente | **PARAR TODO, revisar Business Manager** |
| `190` | Token expirado (antes decíamos `467`, ya no existe en la doc actual) | Renovar token antes de continuar. Auth relacionados: `0`, `200`, `10`, `3` |
| `132001` | Plantilla no aprobada / no existe en ese idioma (antes decíamos `470`) | Revisar status en Meta. Relacionados: `132007` (viola política), `132015` (pausada baja calidad), `132016` (desactivada permanente) |

## Opt-out — Reglas inquebrantables

- Palabras que disparan opt-out (código `WebhookController::OPT_OUT_WORDS`): **STOP, BAJA, CANCELAR, NO** (match exacto del mensaje completo, en `handleTextMessage`). OJO: "NO GRACIAS" NO está en la lista real del código
- **La baja NO llega por botón:** los `button_reply` van a `handleButtonReply`, que solo procesa "no por ahora" (snooze) y "me interesa" (interested). Un botón "No, gracias"/"Baja" NO da de baja. La baja real: (1) texto exacto de arriba, o (2) opt-out nativo de WhatsApp → error `131050`. Verificado contra la doc oficial de Meta (no exige botón, exige "dar opt-out y respetarlo"; el nativo lo cubre)
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

---

# Referencia oficial Meta (API v25, verificada 2026-07-08)

> **PRECEDENCIA:** esto viene directo de la documentación oficial de Meta (developers.facebook.com).
> **Si algo aquí contradice lo de más arriba o el código, MANDA ESTO** - es la fuente de verdad.
> Verificar en la doc oficial si cambian versiones.

## Límites de mensajería (messaging limits) - CRÍTICO

- **`messaging_limit_tier` está DEPRECADO.** Campo vigente: **`whatsapp_business_manager_messaging_limit`**
  (valores `TIER_250`, `TIER_2000`, `TIER_10K`/`10000`, `100000`, `UNLIMITED`). Pedible en phone number, WABA o portfolio.
- **El límite es POR BUSINESS PORTFOLIO (compartido por TODOS los números)**, vigente desde 7-oct-2025.
  Un solo número puede consumir toda la capacidad del portfolio. **Agregar números NO multiplica el volumen.**
- Es el máximo de **usuarios únicos** a los que puedes iniciar conversación (fuera de ventana de servicio) en 24h móviles.
- **Escalado:** empieza en 250. Sube a 2,000 completando un "scaling path" (verificar negocio, o **enviar 2,000
  mensajes entregados a usuarios únicos fuera de ventana en 30 días móviles con plantillas de calidad alta**).
  Luego 10,000 -> 100,000 -> Unlimited por **escalado automático**.
- **Criterio de escalado automático:** mandas mensajes de alta calidad en todos los números/plantillas **Y**
  en los últimos 7 días usaste **>=50%** del límite actual -> sube 1 nivel **en ~6h**.
- **Webhook** `business_capability_update`: `max_daily_conversations_per_business` (v24+) /
  `max_daily_conversation_per_phone` (v23, se retira **feb-2026**). *(Nosotros NO lo usamos: leemos el límite
  en `phoneHealth`, ver sección "Límite de mensajería - cómo lo leemos".)*
- El estado de calidad **"Flagged" ya NO existe** (post 7-oct-2025). Si la calidad baja, el límite **NO** se degrada.
- **Throughput 1,000 msg/s:** requiere portfolio en Unlimited + el número usado con 100k+ usuarios únicos/24h +
  calidad Medium o más -> upgrade en ~12h.

## Tope de marketing POR USUARIO (error 131049) - detalle oficial

- Meta limita cuántas **plantillas de marketing** recibe un usuario de **cualquier** empresa en un periodo,
  según su read-rate reciente y qué tan lleno tiene el inbox. Es **adaptativo** por persona.
- **Números de EE.UU. (+1): Meta NO entrega plantillas de marketing** -> da error. (Somos México, pero ojo si entra un +1.)
- **Países excluidos** (no aplica el tope por-usuario): EEE (Europa), Reino Unido, Japón, Corea del Sur.
  **México NO está excluido -> a nosotros SÍ nos aplica.**
- **Conteo:** cada plantilla de marketing entregada suma. Si el usuario **responde**, abre ventana de servicio
  de 24h y los mensajes dentro de esa ventana **no** cuentan al tope.
- **Reintentos:** esperar **>=24h** para reintentar a un usuario capado. Reintentar de más dentro de 24h ->
  Meta lo bloquea hasta 24h más y sigue devolviendo 131049. **No afecta a los demás usuarios.**

## Calidad de plantilla (template quality rating)

- Ratings: **`GREEN`** (alta), **`YELLOW`** (media, en riesgo), **`RED`** (baja, en peligro de pausa),
  **`UNKNOWN`** (pendiente; las nuevas empiezan aquí).
- Campo API **`quality_score`** `{score, date}` (Template API). En WhatsApp Manager: "Quality pending / High / Medium / Low".
- Alimenta el **pacing** y el **pausing**. Si baja de `APPROVED`, la plantilla no se puede enviar hasta recuperar estado.

## Pausado de plantilla (template pausing)

- Si una plantilla llega a **RED**, se **auto-pausa**: **1ra vez 3h · 2da vez 6h · 3ra vez DESACTIVADA**.
- Pausada = no se puede enviar; la API rechaza (no cobra, no cuenta al límite). Detener campañas que la usen.
- Se despausa sola al cumplir el tiempo, **o** manualmente: `POST /{template_id}/unpause` o en WhatsApp Manager.
  Las pausadas por **pacing** hay que despausarlas **manualmente**.
- Pausar **no** golpea al número al inicio; pero si mandas seguido plantillas de baja calidad, el número eventualmente se ve afectado.
- Avisa por WhatsApp Manager, email y webhook `message_template_status_update`.

## Ritmo de plantilla (template pacing)

- Plantillas nuevas, despausadas, o sin calidad `GREEN` pueden ser "paced".
- API: el response de `/messages` trae `message_status` = **`accepted`** o **`held_for_quality_assessment`**.
- Señal mala -> plantilla `PAUSED`, los mensajes retenidos se **descartan** -> webhook messages `status=failed`, `code=132015`.
- Señal buena -> los retenidos se liberan y se envían. Guardrail: aun con pacing, los de mayor throughput se entregan dentro de ~1h (p99).

## Revisión/aprobación de plantilla

- Aprobación tarda hasta **24h**. Avisa por WhatsApp Manager, email y webhook `message_template_status_update`.
- Aprobada -> estado "Active - Quality pending" (`APPROVED` en API), ya se puede enviar.
- Variables: formato `{{1}}` posicional, secuenciales, sin caracteres especiales (`#`,`$`,`%`), no al inicio/fin (no "dangling").
- Rechazos comunes: variables mal, viola Commerce/Business Policy (no pedir identificadores sensibles completos),
  contenido abusivo/amenazante, **duplicado** (mismo body+footer que otra existente; no aplica a `AUTHENTICATION`).
- Se puede **apelar** (con sample) o editar y resometer (pasa a "In Review", no se envía hasta re-aprobar).

## Códigos de error (oficial, MANDA sobre lo que teníamos)

- **Construir el manejo alrededor de `code`**, NO de `error_subcode` (deprecado v16+) ni del HTTP status.
- **Auth:** `0` (no autenticado), `3`/`10` (permiso), **`190` (token expirado)**, `200` (sin token). **NO existe `467`.**
- **Integridad:** `368` / `131031` (WABA restringida/deshabilitada por política), `130497` (restringida a ciertos países).
- **Envío:** `131026` (no está en WhatsApp / no aceptó ToS / versión vieja), `131047` (pasaron 24h de ventana -> usar plantilla),
  `131048` (restricción de envíos del número), `131049` (tope marketing por-usuario), `131050` (el usuario se dio de baja de marketing),
  `131056` (muchos al mismo destinatario; puedes seguir con otros), `131064` (límite de cuenta por categorización de plantillas),
  `130403` (la empresa bloqueó al usuario).
- **Límites/throughput:** `4` (rate limit de la app), `80007` (rate limit de la WABA), `130429` (throughput de Cloud API).
- **Plantillas (envío):** `132000` (nº de variables no coincide), **`132001` (no aprobada / no existe en ese idioma; NO existe `470`)**,
  `132007` (viola política), `132012` (formato de variable), `132015` (pausada por baja calidad), `132016` (desactivada permanente), `132018` (validación).
- **Plantillas (creación):** familia `2388xxx`. Máx **250 plantillas** por WABA (`2388019`).
- NO nos aplican (otra arquitectura): migración de teléfonos (`2388012/91/93/103`), OBO (`2593079/85`), sync (`2593107/8`),
  Marketing Messages Lite API (`134100/101/102`, `131055`, `1752041`), Flows (`132068/69`), pagos (`134011`), 2FA/registro (`133xxx`).
