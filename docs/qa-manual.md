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
- [ ] **Import con etiqueta**: CSV con columna `etiqueta` → crea la etiqueta y la asigna; el resumen dice cuántas asignó y creó
- [ ] **Import etiqueta a existente**: subir números que YA están con una etiqueta nueva → 0 nuevos, se etiquetan igual, el resumen lo dice
- [ ] **Import no pisa etiquetas**: un contacto con etiqueta previa recibe la nueva sin perder la vieja
- [ ] **Import varias etiquetas**: celda `VIP, Mazatlán` → dos etiquetas al mismo contacto
- [ ] **Import mayúsculas**: `VIP` y `vip` en el mismo archivo → una sola etiqueta, no dos
- [ ] **Import reimportado**: subir el mismo archivo dos veces → no duplica relaciones, `tags_assigned = 0` la segunda vez
- [ ] **Selector de tags refrescado**: tras importar etiquetas nuevas, aparecen en el filtro sin recargar la página
- [ ] **Export con etiquetas**: descargar el Excel de contactos → trae columna `Etiquetas` separada por coma; volver a subir ese archivo re-etiqueta sin duplicar
- [ ] **Opt-out manual**: eliminar contacto desde UI → se marca `opted_out`, NO se borra de BD
- [ ] **Filtro por tag**: aplicar filtro `?tag_id=X` → solo aparecen contactos de ese tag
- [ ] **Asignar/quitar tags**: asignar múltiples tags a un contacto → se guardan correctamente → quitarlos → se eliminan
- [ ] **Pantalla Etiquetas**: aparece en el menú para admin y operator, NO para agente
- [ ] **Catálogo - contadores**: los números de Contactos y Campañas coinciden con la realidad
- [ ] **Catálogo - ver contactos**: clic en el número de contactos → lleva a Contactos filtrado por esa etiqueta, y la URL trae `?tag=ID`
- [ ] **Catálogo - crear**: crear una etiqueta desde la pantalla → aparece en la lista y en el selector de Contactos
- [ ] **Catálogo - renombrar**: renombrar una etiqueta → cambia el nombre pero el identificador NO; subir un Excel con el nombre VIEJO sigue apuntando a la misma etiqueta (no crea duplicada)
- [ ] **Catálogo - nombre repetido**: renombrar a un nombre que ya existe → lo rechaza con aviso
- [ ] **Catálogo - buscar y paginar**: el buscador filtra y el selector Mostrar funciona igual que en las demás tablas
- [ ] **Borrar etiqueta - conteo previo**: borrar una etiqueta con contactos → la confirmación dice cuántos la perderán
- [ ] **Borrar etiqueta - bloqueo**: crear campaña en borrador con esa etiqueta → el bote de basura avisa que no se puede y nombra la campaña; la etiqueta sigue existiendo
- [ ] **Borrar etiqueta - campaña ya enviada**: con campaña `completed` → sí borra, y la campaña queda con segmento vacío sin perder su historial
- [ ] **Borrar etiqueta - contactos intactos**: tras borrar, los contactos siguen existiendo y solo perdieron la etiqueta
- [ ] **Borrar etiqueta - filtro activo**: con el filtro por esa etiqueta puesto, borrarla → el filtro se limpia y la lista se recarga
- [ ] **Pegado masivo**: copiar una columna de ~500 números de Excel y pegarla en el buscador → filtra por esa lista, aparece el chip con encontrados / no dados de alta / inválidos
- [ ] **Pegado con formato**: pegar números escritos `52 923 111 1111` (espacios dentro), uno por línea → los reconoce, `invalid = 0`
- [ ] **Copiar faltantes**: con números que no existen, botón "Copiar faltantes" → deja la lista en el portapapeles
- [ ] **Quitar filtro del pegado**: botón "Quitar filtro" → vuelve la lista completa
- [ ] **Pegado + filtros**: con lista pegada, aplicar estado/tag/entregabilidad → filtra dentro de la lista, no fuera
- [ ] **Filtro entregabilidad por canal**: "Enfriamiento - WhatsApp" y "Enfriamiento - SMS" devuelven conjuntos distintos para un contacto que solo recibió SMS
- [ ] **Precedencia del filtro**: un contacto que recibió hoy sale en "Enviado hoy", NO en "Enfriamiento" (la etiqueta de la fila debe coincidir con el filtro)
- [ ] **Selector Mostrar**: cambiar a 250/500 → la tabla trae esa cantidad y vuelve a la página 1
- [ ] **Mostrar = Todos**: con más de 5,000 resultados → muestra 5,000 y el aviso "Mostrando 5,000 de N"
- [ ] **Selector en otras pantallas**: Campañas, Respuestas SMS y Últimos mensajes del Panel tienen el mismo selector y responden igual

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
- [ ] **Límite de la cuenta (Meta)**: en el Dashboard aparece "Límite de la cuenta (Meta)" si Meta reporta el dato (en sandbox puede no aparecer)

---

## Números de WhatsApp (solo superadmin)

- [ ] **La tarjeta solo la ve superadmin**: entrar como admin/operator → **no** aparece "Números de WhatsApp" en Configuración
- [ ] **Verificar número existente**: botón ✔ → muestra estado/verificación/nombre/calidad de Meta
- [ ] **Alta con datos inválidos (formato)**: escribir letras en Phone number ID/WABA ID → el botón "Verificar y guardar" queda deshabilitado + error rojo inline
- [ ] **Alta con credenciales incorrectas**: ID numérico inexistente → Meta rechaza → mensaje amigable en español ("El Phone number ID no existe...") y **no** se crea la fila
- [ ] **Alta duplicada**: usar un `phone_number_id` ya registrado → "ya está registrado"
- [ ] **Token no se pide**: el formulario no tiene campo token (usa el de la cuenta) ni límite diario
- [ ] **Activar/Desactivar**: el toggle cambia el estado del número

---

## Warm-up / freno del portfolio

- [ ] **Warm-up sube**: con `Setting wa_portfolio_daily_limit` alto y un número que envió ≥50% ayer → `wa:warmup-numbers` duplica su `daily_limit` (topado por el portfolio)
- [ ] **Warm-up no rampa sin portfolio**: sin el Setting → el comando no cambia límites
- [ ] **Warm-down**: número pausado por 131048/131064/368 → su `daily_limit` baja a la mitad (piso 250)
- [ ] **Freno del portfolio**: cuando el total del día alcanza el límite del portfolio → el job reencola para mañana (no envía de más)

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
