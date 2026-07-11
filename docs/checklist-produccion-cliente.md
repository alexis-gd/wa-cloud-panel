# Checklist de paso a producción con el cliente

> Lista de cosas que están en **modo dev/staging** (cuenta y número sandbox de Alexis,
> debug encendido, protecciones relajadas para probar) y que **DEBEN cambiar** antes de
> operar con datos reales del cliente. Marcar cada casilla al hacer el corte.
>
> Contexto: hoy `sender.prestamaz.site` corre contra el WABA/número **sandbox de Alexis**
> (staging). El día que entre la cuenta del cliente, recorrer TODO esto.

---

## 1. Cuenta y número de Meta (WABA del cliente)

- [ ] **`WA_PHONE_ID`** en `.env` → cambiar del sandbox `1082360764952377` al **Phone ID del cliente**.
- [ ] **`WA_WABA_ID`** → cambiar de `1236630511398211` al **WABA ID del cliente**.
- [ ] **`WA_TOKEN`** → System User Token **del cliente** (sin expiración). Verificar permisos
      `whatsapp_business_messaging` + `whatsapp_business_management`.
- [ ] **`WA_APP_SECRET`** y **`WA_WEBHOOK_VERIFY_TOKEN`** → los de la app de Meta del cliente.
- [ ] El número en BD (`phone_numbers`) apunta al del cliente. Re-seed o alta por el panel
      (Configuración → Números). El token vive cifrado en `phone_numbers.token`.
- [ ] **Business Verification** del cliente aprobada en Meta (3-10 días hábiles). Sin esto, Tier 1 (250/día).
- [ ] Webhook de Meta apuntando a `https://sender.prestamaz.site/api/webhook` con el
      `WA_WEBHOOK_VERIFY_TOKEN` nuevo. Suscripciones: `messages`, `message_template_status_update`.
- [ ] Plantillas aprobadas en el WABA del cliente (`prestamaz_interes_v1` u otras). Categoría **Marketing**, `es_MX`, con CAT y baja en el texto.
- [ ] Imagen de header subida: `public/storage/templates/prestamaz_interes_v1.jpg` y `WA_MEDIA_BASE_URL=https://sender.prestamaz.site`.
- [ ] Número de campañas = **SIM dedicada nueva**, NUNCA el número oficial del cliente (669-101-0211).

## 2. Horario de envíos (R1 - modo demo)

- [ ] **`Setting schedule_bypass` = `0`** (o inexistente) en la BD de prod. Comando:
      ```bash
      php artisan tinker
      \App\Models\Setting::set('schedule_bypass','0');
      ```
- [ ] Confirmar que NADIE dejó el modo demo abierto. Con `schedule_bypass=1` se envía a
      cualquier hora (fuera de 9-22h L-V) → riesgo de reportes de spam en Meta.
- [ ] (El guardia de horario ahora también vive en el job `SendWhatsAppMessage`, así que el
      worker 24/7 de Supervisor ya no puede enviar fuera de ventana. No requiere acción, solo
      no encender el bypass.)

## 3. Configuración de entorno (`.env` de prod)

- [ ] **`APP_ENV=production`** ✅ (ya está).
- [ ] **`APP_DEBUG=false`** ✅ (ya está). NUNCA `true` en prod (expone stack traces).
- [ ] **`LOG_LEVEL`** → bajar de `debug` a **`warning`** (o `info`). En `debug` llena disco y
      puede loguear payloads sensibles.
- [ ] **`SMS_WEBHOOK_SECRET`** → **poner** un secreto (igual a la Signing Key del teléfono en
      el gateway). Vacío = el webhook SMS acepta cualquier POST sin firma (opt-outs/entrantes falsos).
- [ ] **`MAIL_*`** → si el panel manda correo (reset de contraseña), configurar SMTP real.
      Hoy apunta a `mailpit`/`hello@example.com` (dev).
- [ ] `QUEUE_CONNECTION=database`, `BROADCAST_DRIVER=pusher` ✅ (ya están).
- [ ] `APP_URL` y `VITE_PUSHER_HOST` = `sender.prestamaz.site` ✅.

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

## 6. Verificación final

- [ ] `php artisan config:cache` + `route:cache` tras cambiar `.env` (los caches viejos leen valores viejos).
- [ ] Health check: `curl -fsS https://sender.prestamaz.site/api/health`.
- [ ] Enviar 1 mensaje de prueba a un número propio pre-registrado, confirmar entrega y estado.
- [ ] Confirmar semáforo del panel (Configuración) en verde y límite de la cuenta correcto.
- [ ] Warm-up: arrancar conservador (Tier 1, 250/día). Dejar que el sistema suba solo.
