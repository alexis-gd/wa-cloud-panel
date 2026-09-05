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
- [ ] **Filtro Cartera**: el desplegable trae solo los estados que existen en la base; elegir uno filtra bien
- [ ] **Cartera se suma**: Cartera = LIQUIDADO + Estado = Activos devuelve la intersección, no la suma
- [ ] **Sin datos, sin filtro**: en una base sin estados de cartera, el desplegable no aparece
- [ ] **Etiquetar todo lo filtrado**: filtrar por Cartera → el aviso dice el total real → confirmar → etiqueta a TODOS, no solo a la página visible
- [ ] **Confirmación con número**: el diálogo dice cuántos contactos va a etiquetar antes de aceptar
- [ ] **No pisa etiquetas**: un contacto con etiqueta previa la conserva y suma la nueva
- [ ] **Repetir no duplica**: etiquetar dos veces el mismo filtro → la segunda dice 0 etiquetados
- [ ] **Filtro por etiqueta + etiquetar**: filtrar por la etiqueta VIP y etiquetar con otra → solo toca a los VIP (no confundir el filtro con la etiqueta que se pone)
- [ ] **Sin filtro no aparece**: con la lista sin filtrar, el aviso de "etiquetar todo lo filtrado" NO se muestra
- [ ] **Con selección tampoco**: al marcar casillas aparece la barra azul y se esconde la de filtro (son dos acciones distintas)
- [ ] **Hover del botón**: el tooltip explica que etiqueta todo el filtro y que no quita etiquetas
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
- [ ] **Escribir no se traba**: con cientos de conversaciones en la lista, teclear en la cajita sale fluido (el texto vive en su propio componente; antes cada tecla redibujaba la lista entera)
- [ ] **El texto no se pierde**: si el envío falla (apaga la red y manda), el mensaje escrito SIGUE en la cajita
- [ ] **Se limpia al enviar bien**: envío exitoso → la cajita queda vacía
- [ ] **La fila se ve igual**: nombre, hora, vista previa, punto de estado, etiqueta y el mini indicador de asignación siguen viéndose como antes (se movieron a un componente)
- [ ] **Filtro por rol agente**: agente solo ve sus conversaciones asignadas, no las de otros agentes
- [ ] **Admin ve todo**: admin ve todas las conversaciones, incluyendo las sin asignar

---

### Reporte de agentes (P1)

- [ ] **Visibilidad**: aparece en el menú para operador y admin; el agente NO lo ve (y la API le responde 403)
- [ ] **Por defecto**: al entrar muestra el día de hoy sin tocar nada
- [ ] **Recibidas**: asignar un chat hoy → sube en "Recibidas en el periodo"; con filtro de ayer, no aparece
- [ ] **Abiertas ahora**: un chat asignado hace un mes cuenta en "Abiertas ahora" aunque el filtro sea de hoy
- [ ] **Reasignada**: al pasar un chat de Ana a Beto, Ana conserva la "recibida" pero pierde la "abierta"
- [ ] **Sin asignar**: soltar un chat → deja de contarle a su agente en "Abiertas ahora"
- [ ] **Agentes en cero**: un agente sin conversaciones aparece con 0, no desaparece
- [ ] **Filtro por agente**: deja una sola fila
- [ ] **Excel**: descarga, abre bien y trae los mismos números que la pantalla
- [ ] **PDF**: descarga, abre bien, con el periodo en el encabezado y los totales al pie
- [ ] **Filtros en la descarga**: filtrar por agente y descargar → el archivo trae solo a ese agente

---

### Reasignación e historial (P2)

- [ ] **Reasignar**: pasar una conversación de un agente a otro → el agente viejo deja de verla, el nuevo la ve
- [ ] **Mismo agente**: reasignar al que ya la tiene → lo rechaza con aviso, no agrega movimiento
- [ ] **Dejar sin asignar**: la conversación queda "Sin asignar" y el historial conserva los movimientos previos
- [ ] **Historial - contenido**: el modal muestra movimiento, agente, fecha en hora de México y quién lo hizo
- [ ] **Historial - sistema**: un reparto automático aparece como "Asignación automática" y "por el sistema", sin nombre de usuario
- [ ] **Historial - baja**: dar de baja a un contacto con agente → queda sin asignar y el historial NO se borra
- [ ] **Historial - permisos**: el agente no ve los botones de reasignar/soltar/historial (y la API le responde 403)
- [ ] **Reparto automático**: una conversación suelta no le cuenta como carga a ningún agente al repartir la siguiente

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

## Sincronización de contactos desde el API del cliente (C1)

> Requiere que el cliente abra el firewall y dé puerto y ruta. Sin eso solo se puede
> verificar que los comandos existen y fallan con un mensaje claro.

- [ ] **Diagnóstico**: `php artisan contactos:probar-api` muestra la config, y sin URL avisa que falta `SYNC_API_URL`
- [ ] **Sin contraseña visible**: la salida del diagnóstico NUNCA imprime la contraseña
- [ ] **Campos**: con el API conectado, el diagnóstico lista los campos que llegan
- [ ] **Modo seco**: `contactos:sincronizar --dry-run` reporta cuántos daría de alta y NO escribe nada
- [ ] **La tabla cuadra**: inválidos + repetidos + excluidos + válidos = registros recibidos. Si no, el comando avisa
- [ ] **Repetidos**: el mismo teléfono dos veces en la respuesta cuenta como 1 válido y 1 repetido, y el desglose por estado no lo suma dos veces
- [ ] **Alta**: `contactos:sincronizar` da de alta solo los nuevos, con `source = api`
- [ ] **No pisa**: un contacto que ya existe conserva su nombre del panel
- [ ] **Respeta la baja**: un contacto `opted_out` que viene en el API NO se reactiva
- [ ] **Etiqueta**: con `SYNC_TAG` puesto, los nuevos quedan con esa etiqueta
- [ ] **Estado de cartera**: cada contacto nuevo queda con su `Estado` en la columna **Cartera** (LIQUIDADO, BURÓ, BAJA)
- [ ] **No crea etiquetas**: tras sincronizar, la pantalla Etiquetas NO tiene etiquetas nuevas con nombre de estado
- [ ] **Su BAJA no es la nuestra**: un contacto con `Estado = BAJA` entra como **Activo**, solo con Cartera = BAJA
- [ ] **Refresca a los que ya existen**: cambiar a mano la Cartera de un contacto, volver a sincronizar → vuelve al valor del API, y el resumen lo cuenta en "Estado actualizado"
- [ ] **El refresco no toca nada más**: ese contacto conserva su nombre del panel y su Baja si la tenía
- [ ] **Se puede congelar**: con `SYNC_REFRESH_STATUS=false` el estado NO se actualiza
- [ ] **Modo seco no refresca**: `--dry-run` reporta "Cambiarían de estado" pero la columna no cambia
- [ ] **Desglose**: `--dry-run` muestra la tabla de cuántos hay de cada estado
- [ ] **Estado nuevo sin tocar código**: si el API manda una clasificación que no conocíamos (pasó con `ACTIVO`), entra sola y aparece en el filtro Cartera sin desplegar nada
- [ ] **Excluir**: con `SYNC_STATUS_EXCLUDE=BURÓ`, esos no se dan de alta y salen en "Excluidos por su estado"
- [ ] **Excluido que ya existe**: con `SYNC_STATUS_EXCLUDE=BURÓ`, a un contacto que YA está y pasa a BURÓ **sí** se le actualiza la Cartera (excluir impide el alta, no el reflejo)
- [ ] **API caído**: si no responde, el comando falla con mensaje claro y no deja nada a medias
- [ ] **Scheduler**: `php artisan schedule:list` muestra `contactos:sincronizar` a las 04:00

---

## API de contactados (S1)

- [ ] **Sin llave**: `GET /api/contacted?date=...` sin `X-API-Key` → 401
- [ ] **Un día**: devuelve nombre y número de los contactados ese día, una fila por contacto
- [ ] **Corte del día**: un envío de las 23:30 CST sale en ese día, no en el siguiente
- [ ] **Fallidos**: un mensaje `failed` o `discarded` NO aparece
- [ ] **Dos canales**: cuenta WhatsApp y SMS
- [ ] **Rango**: `from`/`to` incluye las dos fechas
- [ ] **Errores**: sin fecha → `MISSING_DATE`; fecha y rango juntos → `AMBIGUOUS_RANGE`; `to` antes de `from` → `INVALID_RANGE`
- [ ] **Paginado**: recorrer todas las páginas no repite ni pierde contactos

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
