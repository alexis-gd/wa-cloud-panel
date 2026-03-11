# WA Cloud Panel — Contexto del Proyecto

## Qué es esto
Sistema de envío masivo de WhatsApp para empresa de préstamos (México).
**Caso de uso: MARKETING/PUBLICIDAD** — promocionar el servicio de préstamos a prospectos (ej: "¿Necesitas dinero? Préstamos rápidos, aplica ahora"). NO es cobranza ni recordatorios de pago (eso sería Stage futuro si aplica).
Objetivo: 200,000 contactos/mes vía WhatsApp Cloud API oficial de Meta.
Desarrollado en etapas progresivas con Laravel. Este es el proyecto principal (no el prototipo).
Prototipo original (solo referencia): C:\xampp5\htdocs\wa-cloud-panel-v1\

## Principio de diseño: Sistema a prueba de errores del cliente
El cliente usará el sistema sin supervisión técnica. Cada feature debe diseñarse asumiendo que el usuario NO conoce las políticas de Meta. El sistema debe hacer imposible (o muy difícil) cometer errores que causen suspensión.

Reglas concretas para todo desarrollo futuro:
1. **Validar antes de enviar** — nunca enviar a números inválidos, mal formateados o en lista negra
2. **Warm-up automático** — el sistema impone los límites diarios, el cliente no puede subirlos manualmente más allá del tier actual
3. **Horario forzado** — el scheduler solo corre en horario permitido, no hay override manual
4. **Opt-out inmediato** — si un contacto responde STOP/NO/BAJA, se bloquea automáticamente y nunca más se le envía
5. **Plantillas solo aprobadas** — el selector de plantillas solo muestra las que tienen status "Approved" en Meta; nunca permitir escribir nombres a mano en producción
6. **Feedback visible** — el panel siempre muestra el estado del número (calidad, tier actual, límite diario disponible)
7. **Sin acceso a configuración crítica** — el cliente no ve ni edita tokens, phone IDs, ni configuración de Meta directamente

## Propuesta técnica completa
Documento: C:\xampp5\htdocs\wa-cloud-panel-v1\propuesta-whatsapp-sms-masivo.docx
Puntos clave del documento:
- Multi-número: 2-4 números de envío + 1 número de contacto dedicado
- Warm-up obligatorio: Semana 1: 500/día → Tier 2: 10K/día → Tier 3: 100K/día (3-4 semanas)
- Modelo PMP (desde Jul 2025): por mensaje entregado. Utility ~$0.01 USD, Marketing ~$0.04 USD
- Límites desde Oct 2025: nivel Business Portfolio, no por número individual
- Cumplimiento: LFPDPPP 2025, horario cobranza L-V 7AM-10PM, opt-out automático

## Stack técnico (decisión final, no cambiar)
- Backend: Laravel PHP 8.1
- Frontend: Vue.js 3 vía CDN (sin build step en Stage 1)
- DB: MySQL
- Queue driver: `database` (Stage 1, sin Redis), cambiar a `redis` en Stage 2 con VPS
- Hosting producción: VPS Ubuntu 22.04 + Nginx + PHP-FPM (NO cPanel)
- VPS recomendado: Hetzner ~$5/mes (2 vCPU, 2GB RAM)
- WhatsApp Cloud API directa sin intermediarios (no Twilio, no MessageBird)

## Checklist de progreso

### Stage 1: Fundación + envío de plantillas
- [x] Plan arquitectónico aprobado
- [x] CLAUDE.md creado
- [x] Proyecto Laravel instalado (`composer create-project`)
- [x] `.env` configurado (DB + WhatsApp tokens)
- [x] `php artisan key:generate`
- [x] 3 migraciones creadas y corridas
- [x] Fila inicial en `phone_numbers` insertada
- [x] `app/Services/WhatsApp/WhatsAppClient.php`
- [x] `app/Services/WhatsApp/TemplateBuilder.php`
- [x] `app/Models/PhoneNumber.php`
- [x] `app/Models/WaTemplate.php`
- [x] `app/Models/MessageLog.php`
- [x] `app/Http/Middleware/ApiKeyMiddleware.php`
- [x] `app/Http/Controllers/Api/TemplateController.php`
- [x] `app/Http/Controllers/Api/WebhookController.php`
- [x] `app/Http/Controllers/Api/DashboardController.php`
- [x] `routes/api.php` con todas las rutas Stage 1
- [x] `resources/views/app.blade.php` (shell Vue)
- [x] `public/assets/js/app.js` (Vue 3 CDN: health, send-test, logs)
- [x] Revisar políticas Meta anti-baneo + duración de token
- [x] Verificación end-to-end completa (primer mensaje real enviado 2026-03-11)

### Stage 2 (futuro): Contactos + Campañas + Queue
- [ ] Upload Excel (PhpSpreadsheet)
- [ ] Modelo Contact + Campaign
- [ ] MySQL-based job queue
- [ ] Scheduler con horario legal (L-V 7AM-10PM)
- [ ] Circuit breaker por calidad de número

### Stage 3 (futuro): Conversaciones entrantes
- [ ] Inbound message threading
- [ ] Multi-agente de atención

### Stage 4 (futuro): Producción
- [ ] VPS setup + deploy
- [ ] Redis + Laravel Horizon
- [ ] Multi-número con balanceo
- [ ] Dashboard métricas en tiempo real

## Credenciales WhatsApp
- Phone ID: 1082360764952377
- WABA ID: 1236630511398211
- API Version: v22.0
- Token: ver .env (NUNCA commiteado en git)
- Token se obtiene en: https://developers.facebook.com → tu app → WhatsApp → API Setup
- Nota: El token temporal vence en 24h. Para producción usar System User en business.facebook.com

## Estructura del proyecto
```
app/
  Services/
    WhatsApp/
      WhatsAppClient.php    <- UNICO lugar con curl/HTTP. Nunca curl directo en controllers
      TemplateBuilder.php   <- construye payloads JSON de plantillas
    WebhookProcessor.php    <- procesa eventos entrantes de Meta
  Models/
    PhoneNumber.php         <- cast encrypted: en token
    WaTemplate.php
    MessageLog.php          <- logSend(), updateFromResponse(), updateStatus()
    Contact.php             <- Stage 2
    Campaign.php            <- Stage 2
  Http/
    Controllers/Api/
      TemplateController.php
      WebhookController.php
      DashboardController.php
    Middleware/
      ApiKeyMiddleware.php  <- valida X-API-Key header
  Jobs/
    SendWhatsAppMessage.php <- Stage 2
database/migrations/
  *_create_phone_numbers_table.php
  *_create_wa_templates_table.php
  *_create_message_log_table.php
routes/
  api.php
  web.php
resources/views/app.blade.php
public/assets/js/app.js
```

## Rutas API (Stage 1)
```
GET  /api/health              -> {"status":"ok","db":"ok"}
GET  /api/templates           -> lista de wa_templates
POST /api/templates/send-test -> {"template_name","language_code","to","body_vars":[]}
GET  /api/dashboard/stats     -> últimos 20 mensajes
GET  /webhook                 -> hub_challenge verification (Meta)
POST /webhook                 -> eventos Meta (delivery receipts, inbound)
```

## Decisiones de seguridad (no cambiar)
1. WA_WEBHOOK_VERIFY_TOKEN = secreto SEPARADO del bearer token (el v1 tenía este bug)
2. Validar X-Hub-Signature-256 en cada webhook POST contra WA_APP_SECRET
3. Tokens en DB con cast `encrypted:` de Eloquent (AES-256 via APP_KEY)
4. Endpoints API protegidos con X-API-Key header (ApiKeyMiddleware)
5. Rate limiting: `throttle:60,1` en rutas API (Laravel built-in)
6. .env en .gitignore SIEMPRE

## Reglas de desarrollo (no cambiar)
1. Todo HTTP a Meta pasa por WhatsAppClient::post() — nunca curl disperso
2. Cada envío crea registro en message_log ANTES de llamar a la API
3. Queue driver = database Stage 1, redis Stage 2
4. No usar el prototipo v1 como base — solo consultar su lógica como referencia
5. Vue 3 via CDN en Stage 1, no Vite/build en Stage 2+

## Prompt de retoma para nueva sesión
Si cambias de ruta o abres nueva sesión, pega esto:
"Continuamos el desarrollo de wa-cloud-panel. Lee el CLAUDE.md del proyecto para retomar el contexto. Siguiente paso según el checklist: [describe qué sigue]"
