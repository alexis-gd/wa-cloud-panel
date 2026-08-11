# Checklist de paso a producción con el cliente

> Lista de cosas que están en **modo dev/staging** (cuenta y número sandbox de Alexis,
> debug encendido, protecciones relajadas para probar) y que **DEBEN cambiar** antes de
> operar con datos reales del cliente. Marcar cada casilla al hacer el corte.
>
> Contexto: hoy `sender.prestamaz.site` corre contra el WABA/número **sandbox de Alexis**
> (staging). El día que entre la cuenta del cliente, recorrer TODO esto.

---

## 1. Cuenta y número de Meta (WABA del cliente) - ✅ HECHO 2026-08-09

> Datos finales: portafolio `prestamazmzt` (`business_id 1025563076289753`), app **`PRESTAMAZ-SENDER`**
> (`2145308996407972`), system user **`panelsender`**, WABA **`prestamaz sofom`** = `923331644153132`,
> número `+52 669 252 2844` = phone id `1346603205191948`.
> El cliente borró y recreó WABAs varias veces durante el alta: **si algo no cuadra, releer los IDs en
> Business Manager antes de tocar el `.env`** - los de arriba mueren si vuelve a borrar la cuenta.

- [x] **`WA_PHONE_ID`** = `1346603205191948`.
- [x] **`WA_WABA_ID`** = `923331644153132`. Ojo: es el único que usa `wa:sync-templates`; si queda
      apuntando a otra WABA, el panel sincroniza plantillas de la cuenta equivocada **sin dar error**.
- [x] **`WA_TOKEN`** → System User Token de `panelsender`, sin expiración, con
      `whatsapp_business_messaging` + `whatsapp_business_management`. Verificar con
      `GET /v22.0/me?fields=id,name` (debe responder `panelsender`).
- [x] **`WA_APP_SECRET`** → el de `PRESTAMAZ-SENDER` (Configuración de la app → Básica).
      **`WA_WEBHOOK_VERIFY_TOKEN`** no cambia: lo inventamos nosotros, Meta solo lo repite.
- [x] Número en BD (`phone_numbers`) = `prestamaz sofom`, activo. `Sandbox Prestamaz` y
      `prestamaz beta` quedaron **inactivos, no borrados** (conservan su historial).
- [ ] **Business Verification**: 🟠 **en revisión** desde 2026-08-02. Mientras, el portafolio está en
      `TIER_2K` (2,000 usuarios únicos/24h, compartidos por toda la cuenta).
- [x] Webhook `https://sender.prestamaz.site/api/webhook` verificado, con `messages` y
      `message_template_status_update` suscritos. **Además** la WABA debe estar suscrita a la app:
      `GET /{waba_id}/subscribed_apps` tiene que listar `PRESTAMAZ-SENDER` (si no,
      `POST /{waba_id}/subscribed_apps`). Sin eso el registro del número falla con `code 100 / subcode 33`.
- [x] **App publicada** (modo Activo). Requisito: URL de política de privacidad
      (`https://prestamaz.mx/aviso-de-privacidad`), icono y categoría. **Sin publicar, el webhook solo
      recibe eventos de prueba** - no llegan entregas ni respuestas reales.
- [x] Plantilla `prestamaz_demo_v1` (Marketing, `es_MX`, con CAT y baja en el cuerpo) aprobada en la
      WABA del cliente. Las plantillas **no se heredan** entre WABAs: hay que recrearlas.
- [x] Imagen de header: `storage/app/public/templates/prestamaz_demo_v1.jpg` (el archivo debe llamarse
      **igual que la plantilla**) + `WA_MEDIA_BASE_URL=https://sender.prestamaz.site`. Si falta, el envío
      cae a la URL `scontent.whatsapp.net` que Meta **no entrega** y todo sale `failed`.
- [x] Número de campañas = SIM dedicada, NO el número oficial del cliente (669-101-0211).
- [x] Validado end-to-end en prod (2026-08-09): plantilla enviada a dos números, imagen y botones OK,
      estados `enviado → entregado → leído`, entrante → conversación.

## 2. Horario de envíos (R1 - modo demo)

- [x] **`Setting schedule_bypass` = `0`** (o inexistente) en la BD de prod. **Hecho 2026-07-11: prod en 0.**
      Confirmar que NADIE dejó el modo demo abierto: con `schedule_bypass=1` se envía a cualquier hora
      (fuera de 9-22h L-V) → riesgo de reportes de spam en Meta. Comando:
      ```bash
      php artisan tinker
      \App\Models\Setting::set('schedule_bypass','0');
      ```

> Nota (no requiere acción): el guardia de horario también vive en el job `SendWhatsAppMessage`, así
> que el worker 24/7 de Supervisor no puede enviar fuera de ventana. Solo hay que no encender el bypass.

## 3. Configuración de entorno (`.env` de prod)

- [x] **`APP_ENV=production`** (ya está).
- [x] **`APP_DEBUG=false`** (ya está). NUNCA `true` en prod (expone stack traces).
- [x] **`LOG_LEVEL`** = `warning`. **Hecho 2026-07-11: bajado de `debug`.**
- [x] **`SMS_WEBHOOK_SECRET`** → **poner** un secreto (igual a la Signing Key del teléfono en
      el gateway). Vacío = el webhook SMS acepta cualquier POST sin firma (opt-outs/entrantes falsos).
      **Hecho 2026-07-11: secreto configurado en panel + gateway y probado end-to-end (delivered + STOP).**
- [ ] **`MAIL_*`** → si el panel manda correo (reset de contraseña), configurar SMTP real.
      Hoy apunta a `mailpit`/`hello@example.com` (dev). Pendiente (confirmar si el panel usa correo).
- [x] `QUEUE_CONNECTION=database`, `BROADCAST_DRIVER=pusher` (ya están).
- [x] `APP_URL` y `VITE_PUSHER_HOST` = `sender.prestamaz.site`.

## 4. Datos / cuentas de prueba

- [ ] **NO** correr `ContactSeeder` en prod (siembra los 4 números del equipo: Alexis, Heriberto,
      Joseph, Juan Pérez). Receta limpia: `db:clean-demo --contacts --users --force` →
      `db:seed --class=UserSeeder`. Ver [limpieza-y-seeds.md](limpieza-y-seeds.md).
- [ ] **Cambiar las contraseñas** de los usuarios semilla (`superadmin1234`, `admin1234`,
      `agente1234`) tras el primer login, o crear los usuarios reales del cliente a mano.
- [ ] Verificar dominios de email de usuarios = `@prestamaz.mx` (con Z), no `@prestamas.mx`.
- [ ] Borrar cualquier campaña / conversación / log de prueba: `php artisan db:clean-demo --force`.
- [ ] `demoReset` (Configuración → botón de demo): **no usarlo con datos reales** - reactiva
      bajas de WhatsApp (opt-out → active), lo cual es irreversible por ley. Considerar ocultarlo.

## 5. SMS (gateway capcom6)

- [ ] `SMS_GATEWAY_URL`, `SMS_GATEWAY_LOGIN`, `SMS_GATEWAY_PASSWORD` = los de producción (VPS).
- [ ] `SMS_WEBHOOK_SECRET` puesto (ver punto 3).
- [ ] Teléfono(s) del pool registrados en el gateway, con Autostart ON y batería sin restricción (MIUI).

## 6. Límites de subida del servidor

Aplica a la imagen de plantilla (tope de Meta: 5 MB) y al Excel de contactos. Los defaults son
**más bajos** que eso, así que sin tocarlos el archivo se corta antes de llegar a Laravel.

- [ ] **nginx**: `client_max_body_size 8M;` dentro del `server {}` (default: **1M**).
      Verificar: `grep -r client_max_body_size /etc/nginx/`
- [ ] **PHP-FPM**: `upload_max_filesize = 8M` y `post_max_size = 8M` (defaults: **2M** y 8M).
      Verificar: `php -i | grep -E "upload_max_filesize|post_max_size"`
- [ ] Reiniciar: `sudo systemctl reload nginx && sudo systemctl restart php8.2-fpm`
- [ ] Probar subiendo una imagen de ~3 MB en **Plantillas**. Debe guardarse, no dar 413.

> Detectado el 2026-08-09: el VPS tenía `upload_max_filesize = 2M`, así que cualquier imagen de
> plantilla arriba de 2 MB fallaba aunque el panel dijera que acepta 5 MB.

## 7. Verificación final

- [ ] `php artisan config:cache` + `route:cache` tras cambiar `.env` (los caches viejos leen valores viejos).
- [ ] Health check: `curl -fsS https://sender.prestamaz.site/api/health`.
- [ ] Enviar 1 mensaje de prueba a un número propio pre-registrado, confirmar entrega y estado.
- [ ] Confirmar semáforo del panel (Configuración) en verde y límite de la cuenta correcto.
- [ ] Warm-up: arrancar conservador (Tier 1, 250/día). Dejar que el sistema suba solo.
