# QA Manual — wa-cloud-panel

Checklist de pruebas manuales. Ejecutar antes de cada entrega al cliente o después de cambios grandes.
Marcar `[x]` al verificar. Resetear a `[ ]` antes de la siguiente ronda de QA.

---

## Campañas

- [ ] **Happy path**: crear campaña con plantilla aprobada + contactos activos → ejecutar → verificar que los logs muestran `sent` → luego `delivered` → luego `read`
- [ ] **Cooldown**: ejecutar campaña a contacto que ya recibió mensaje hace menos de N días → debe aparecer `discarded` con `discard_reason = cooldown`
- [ ] **Dedup diario**: enviar dos veces al mismo contacto el mismo día → el segundo debe ser `discarded` con `discard_reason = already_sent_today`
- [ ] **Contacto opt-out**: incluir en campaña un contacto con `status = opted_out` → debe descartarse, no enviarse
- [ ] **Contacto inválido**: incluir número inexistente en WhatsApp → error 131026 → debe marcarse inválido en BD, no reintentar
- [ ] **Error API Meta**: enviar con token expirado → el job debe fallar y marcar `failed` después de 3 intentos
- [ ] **Campaña pausada**: ejecutar campaña → pausar a mitad → verificar que los jobs pendientes no continúan
- [ ] **Horario fuera de ventana**: intentar ejecutar antes de las 9AM o después de las 10PM → scheduler debe bloquear
- [ ] **Filtro por tag**: crear campaña con tag específico → solo se envía a contactos de ese tag, no a todos
- [ ] **Auto-completar**: cuando todos los contactos son procesados → campaña pasa automáticamente a `completed`
- [ ] **Logs de campaña**: en detalle de campaña, verificar que aparecen todos los contactos con su estado y `discard_reason` si aplica
- [ ] **Balanceo multi-número** *(nuevo)*: con 2+ números activos, ejecutar campaña → en los logs Laravel ver `phone_numbers: [1, 2]` → los jobs se distribuyen en round-robin → cada número recibe ~50% de contactos
- [ ] **Sin números disponibles** *(nuevo)*: poner todos los números en `is_active = false` → intentar ejecutar campaña → responde 422 con `code: NO_PHONE_AVAILABLE`
- [ ] **Número pausado excluido del balanceo** *(nuevo)*: poner un número en `paused_until = futuro` → ejecutar campaña → solo el número activo recibe los jobs

---

## Contactos

- [ ] **Upload happy path**: subir Excel con teléfonos válidos → reporte muestra aceptados/duplicados/formato inválido
- [ ] **Duplicados**: subir el mismo teléfono dos veces → solo se guarda uno
- [ ] **Formato inválido**: incluir teléfono de 7 dígitos o sin prefijo → rechazado, aparece en reporte como inválido
- [ ] **Opt-out manual**: eliminar contacto desde UI → se marca `opted_out`, NO se borra de BD
- [ ] **Filtro por tag**: aplicar filtro `?tag_id=X` → solo aparecen contactos de ese tag
- [ ] **Asignar/quitar tags**: asignar múltiples tags a un contacto → se guardan correctamente → quitarlos → se eliminan

---

## Conversaciones

- [ ] **Happy path**: contacto responde → aparece en lista → agente selecciona → ve historial → responde texto libre → mensaje llega al celular
- [ ] **Ventana cerrada**: contacto no responde en 24h → campo de texto deshabilitado → chip "Cerrada" en lista
- [ ] **Opt-out por texto**: contacto escribe "STOP" → estado cambia a `opted_out` → chip "Baja" → campo de texto bloqueado con aviso
- [ ] **Snooze por botón**: contacto hace clic en botón "No por ahora" de plantilla → snooze activado (NO opt-out) → chip "Snooze"
- [ ] **Auto-asignación (least_chats)**: llega mensaje nuevo de contacto sin asignar → agente con menos conversaciones activas recibe la asignación
- [ ] **Auto-asignación (first_available)**: cambiar modo a `first_available` → llega mensaje → va al primer agente activo
- [ ] **Sin agentes activos**: todos los agentes en `is_active = false` → llega mensaje → conversación queda "Sin asignar" (chip naranja)
- [ ] **Claim**: agente hace clic en "Tomar conversación" → queda asignada a él → aparece barra verde en su lista
- [ ] **Reasignar**: admin cambia asignación de agente A a agente B → agente A ya no la ve en su lista
- [ ] **No reasignar en mensajes siguientes**: contacto ya asignado envía otro mensaje → la asignación no cambia
- [ ] **Respuestas rápidas**: clic en chip de respuesta rápida → carga texto → se envía → aparece en historial
- [ ] **Filtro por rol agente**: agente solo ve sus conversaciones asignadas, no las de otros agentes
- [ ] **Admin ve todo**: admin ve todas las conversaciones, incluyendo las sin asignar

---

## Configuración

- [ ] **Cambiar modo asignación**: cambiar a `first_available` → guardar → llega mensaje nuevo → va al primer agente
- [ ] **Cambiar cooldown**: reducir a 7 días → ejecutar campaña → el job respeta el nuevo valor
- [ ] **Token inválido**: pegar token falso en campo → debe rechazarse con mensaje de error de Meta antes de guardar
- [ ] **Salud del número**: widget muestra calidad GREEN/YELLOW/RED y modo SANDBOX/LIVE correctamente
- [ ] **Circuit breaker**: número pausado por error 131048 → widget muestra `paused_until` → campañas no se envían con ese número
- [ ] **Expiración de sesión 8h** *(nuevo)*: verificar en Tinker que `PersonalAccessToken::latest()->first()->expires_at` sea `now() + 8h` tras hacer login → con token expirado → frontend redirige a login con 401

---

## Webhook / Estados de mensajes

- [ ] **delivered**: Meta confirma entrega → log y conversación pasan de `sent` a `delivered`
- [ ] **read**: contacto abre el mensaje → pasa a `read`
- [ ] **failed**: error en envío → estado `failed` en log
- [ ] **Firma webhook inválida**: petición sin `X-Hub-Signature-256` → responde 403, no procesa nada

---

## Auth y roles

- [ ] **Login admin**: puede ver todas las secciones del menú
- [ ] **Login operator**: puede ver Campañas, Contactos, Dashboard, Conversaciones — NO Configuración avanzada ni Usuarios
- [ ] **Login agent**: solo ve Conversaciones — no puede acceder a Contactos, Campañas, etc.
- [ ] **Agente accede a Contactos vía API**: `GET /api/contacts` con token de agente → 403
- [ ] **Operador accede a settings admin via API**: `PUT /api/settings/assignment-mode` con token de operator → 403
- [ ] **Sesión expirada**: con token Sanctum vencido → responde 401, frontend redirige a login

---

## Notas de ejecución

- Usar los 4 terminales: `artisan serve` + `npm run dev` + `ngrok` + `queue:work --tries=3`
- Para probar webhooks reales (delivered/read): ngrok debe estar activo y configurado en Meta
- Para probar warm-up y cooldown: ajustar temporalmente el valor de `cooldown_days` a 0 días en BD directamente (luego revertir)
- Números de prueba verificados en sandbox: `529231311146` (Alexis) · `529231122058` (Prueba 2)
