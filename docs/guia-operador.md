# Guía del Operador — Prestamaz Panel

> Para el equipo de Prestamaz. No se requiere conocimiento técnico.
> Si algo no funciona como se describe aquí, contactar al administrador del sistema.

---

## 1. Acceso al panel

1. Abrir el navegador y entrar a la URL del sistema (el administrador te la proporcionará).
2. Ingresar con tu correo y contraseña.
3. Si olvidaste tu contraseña, pedirle al administrador que la restablezca.

**Roles disponibles:**
- **Administrador** — acceso total (contactos, campañas, respuestas SMS, plantillas, usuarios, conversaciones)
- **Operador** — Dashboard, contactos, campañas, respuestas SMS y conversaciones (todas). No gestiona plantillas ni usuarios
- **Agente** — **solo Conversaciones**, y solo las asignadas a él. Al entrar cae directo en esa pantalla; no ve Dashboard, Contactos, Campañas ni Respuestas SMS

---

## 2. Dashboard

Al entrar verás el dashboard con:

- **Métricas de mensajes**: enviados, entregados, leídos y fallidos (totales acumulados).
- **Base de contactos**: total, activos, bajas, inválidos e **inalcanzables**. Un inalcanzable
  es un contacto que recibió 2+ mensajes en los últimos 30 días sin que ninguno se entregara
  (probablemente bloqueó el número); el sistema lo excluye de campañas para cuidar la calidad.
  A diferencia de las bajas, un **admin sí puede reactivarlo** desde Contactos (ver sección 4).
- **Meta mensual**: barra de progreso que muestra cuántos mensajes se han enviado este mes
  versus la meta configurada (por defecto 200,000). Los colores indican el ritmo de avance:
  - Azul — buen ritmo (≥60% de la meta)
  - Amarillo — por debajo del ritmo esperado (≥30%)
  - Rojo — muy por debajo (<30%)
  - Verde — meta alcanzada (100%)
  También muestra cuántos días quedan en el mes y cuántos mensajes faltan para la meta.
  El administrador puede cambiar la meta en **Configuración → Meta mensual de envíos**.
- **Gráfica de envíos (14 días)**: barras por día mostrando enviados / entregados / leídos / fallidos.
  Usa el botón ↺ para refrescar si acabas de ejecutar una campaña.
- **Salud del número**: muestra la calidad del número WhatsApp en semáforo verde/amarillo/rojo,
  cuántos mensajes se han enviado hoy vs. el límite diario, y si el circuito está pausado.
- **Enviar mensaje de prueba** *(solo administradores)*: para probar una plantilla con un contacto específico antes de lanzar campaña. Los operadores no ven este botón.
- **Últimos mensajes**: tabla de envíos recientes con su estado. Incluye WhatsApp y SMS mezclados;
  la columna **Canal** los distingue con un ícono (verde de WhatsApp o sobre azul de SMS). Puedes
  filtrar por estado (enviados / entregados / leídos / fallidos) y navegar entre páginas.

> ⚠️ Si el semáforo está en **ROJO** o aparece **PAUSADO**, no ejecutar campañas hasta que
> el administrador lo revise. El sistema se pausó automáticamente para proteger la cuenta.

---

## 2.1 Notificaciones (campana en la barra superior)

En la esquina superior derecha del panel hay un ícono de campana 🔔. Cuando hay mensajes que no se entregaron, aparece un número rojo indicando cuántas alertas nuevas hay.

**Al hacer clic en la campana:**
- Se abre un panel con las últimas 20 alertas del sistema.
- Las alertas sin leer aparecen con fondo azul claro.
- Al abrir el panel, todas las alertas se marcan como leídas automáticamente.

**Tipos de alertas:**
- **Entrega fallida** — un mensaje no llegó al destinatario. El motivo se describe en lenguaje claro.
  - Ejemplo: "El mensaje a Juan García (529231311146) no fue entregado. Meta pausó temporalmente las entregas por calidad del número."
  - Si ves muchas alertas de este tipo, revisa la **Salud del número** en el Dashboard.
- **Webhook SMS sin respuesta** — el sistema está enviando SMS pero no recibe de vuelta las
  confirmaciones de entrega ni las respuestas. Avisa al administrador: hay que revisar el gateway
  o el teléfono. El detalle está en **Configuración → Salud del webhook SMS**. La alerta se limpia
  sola cuando el webhook vuelve a recibir eventos.

> No es necesario actuar en cada alerta. Son informativas. Si el mensaje falló por un bloqueo temporal, el sistema lo retomará automáticamente al levantarse el bloqueo.

---

## 3. Cargar contactos

1. Ir a **Contactos** en el menú lateral.
2. Clic en **Importar Excel**.
3. Subir el archivo `.xlsx`. Formato requerido:

   | Columna A | Columna B (opcional) |
   |-----------|----------------------|
   | Teléfono  | Nombre               |

   - Los teléfonos deben estar en formato mexicano: `529231311146` (sin +, con clave de país).
   - La primera fila puede ser encabezado — el sistema la detecta automáticamente.

4. El sistema mostrará un resumen: **aceptados / duplicados / formato inválido**.
   - Duplicados: ya existen en la base de datos, no se agregan de nuevo.
   - Formato inválido: número fuera del rango esperado, se ignoran.

5. Los contactos dados de **baja** (que pidieron baja anteriormente) nunca reaparecen aunque se vuelvan a importar.

> 💡 Para exportar los contactos actuales a Excel, usar el botón **Exportar** en la pantalla de Contactos.

### Agregar un contacto manualmente (sin Excel)

Para cuando consigues un prospecto suelto (referido, llamada, walk-in):

1. En **Contactos**, clic en **Agregar contacto**.
2. Escribir **solo los 10 dígitos** del celular (sin 52 ni +). El sistema agrega el +52 (México)
   automáticamente. El campo solo acepta números y un máximo de 10 dígitos. Al completarlos, el
   sistema **verifica el número al instante** y muestra un aviso:
   - ✅ **Disponible** - se puede agregar.
   - ⚠️ **Ya existe** - muestra su estado (activo, baja, inválido, inalcanzable). No se puede volver a agregar (se conserva el registro original).
   - ❌ **Deben ser 10 dígitos** - faltan o sobran dígitos.
3. Escribir el nombre (opcional) y clic en **Guardar**.

> El botón Guardar solo se activa si el número es válido y no existe. Un número dado de baja no se puede reincorporar por esta vía.

### Columnas de la tabla de contactos

La tabla tiene dos columnas de estado (el ícono **?** junto al título las explica):

- **Estado** - identidad del contacto:
  - **Activo** - normal.
  - **Baja** - pidió baja (tooltip muestra fecha y origen).
  - **Inválido** - número no existe en WhatsApp.
  - **Inalcanzable** - recibió 2+ mensajes en 30 días sin que ninguno se entregara (probablemente
    bloqueó el número). Se marca automáticamente y se excluye de campañas. **Es el único estado
    reversible**: un admin puede reactivarlo (botón **Reactivar**) si hay evidencia de que el número
    volvió a ser alcanzable (por ejemplo, si el contacto escribió). Filtra por **"Inalcanzables"**
    arriba de la tabla para encontrarlos.
- **Entregabilidad** - si le llega **ahora mismo**. Se muestra **por canal**, con dos etiquetas:
  una de **WhatsApp** (icono verde) y otra de **SMS** (icono de celular). Cada canal lleva su
  propio conteo, así que un contacto puede estar **Disponible en WhatsApp** y a la vez **En cooldown
  en SMS** (o al revés). Estados de cada etiqueta:
  - 🟢 **Disponible** - se le puede enviar por ese canal.
  - ⚪ **En snooze** - el contacto pidió "No por ahora"; no se le envía hasta que pase (tooltip con la fecha).
  - 🟠 **En cooldown** - recibió hace poco **por ese canal**; no se le envía hasta que pase (tooltip con la fecha).
  - 🔵 **Enviado hoy** - ya recibió hoy **por ese canal**, no se reenvía el mismo día.
  - 🔴 **No recibe** - bloqueado en ese canal (baja / inválido / inalcanzable en WhatsApp; baja / bloqueo / inválido en SMS).

> Un contacto puede estar **Activo** pero **En snooze**, **En cooldown** o **Enviado hoy** - es normal, solo significa que ahorita no se le envía para no saturarlo. Y como es por canal, puede seguir disponible por el otro canal.

**Chip rojo "Baja SMS"** (debajo del Estado): aparece cuando el contacto **no recibe SMS**
(pidió baja por SMS con STOP/BAJA, quedó bloqueado o el número no recibe SMS). Es un eje
**independiente** del Estado: un contacto puede estar **Activo** (le llega WhatsApp) y a la vez
tener **Baja SMS** (no le llega SMS). El tooltip explica el motivo. Para ver solo estos, usa el
filtro **"Solo bajas SMS"** arriba de la tabla.

### Dar de baja vs Eliminar - no son lo mismo

- **Dar de baja** (botón rojo, cualquier operador) - el contacto **pidió baja**. Es cumplimiento: se marca como baja y **nunca más** se le envía. Queda en la base para auditoría.
- **Eliminar** (🗑, solo administradores) - **limpieza** de basura o números de prueba. Lo quita de listas y campañas. Es recuperable y **no** ensucia las estadísticas de bajas. Úsalo para datos erróneos, no para bajas reales.

---

## 4. Tags — etiquetar contactos

Los tags permiten agrupar contactos para segmentar campañas.

1. Ir a **Contactos** y hacer clic en el icono 🏷️ (etiqueta) de cualquier contacto.
2. En el panel que abre:
   - **Seleccionar tags** existentes con el selector múltiple.
   - **Crear nuevo tag**: escribir el nombre en el campo de texto y presionar Enter o clic en "Crear".
   - Clic en **Guardar** para aplicar los cambios al contacto.
3. La columna **Tags** en la tabla de contactos muestra las etiquetas asignadas.
4. Para **filtrar la lista por tag**: usar el selector "Todos los tags" en la barra de búsqueda de Contactos.

### Asignar un tag a varios contactos a la vez

1. Marcar la casilla de cada contacto en la tabla (o la casilla del encabezado para seleccionar todos los de la página).
2. Aparecerá una barra arriba de la tabla con los contactos seleccionados.
3. Elegir el tag en el selector y clic en **Asignar tag**.
   - ¿No existe el tag? Clic en **Nuevo tag**, escribir el nombre y **Crear** — queda seleccionado listo para asignar.
4. El tag se **agrega** a todos los seleccionados sin quitar sus tags actuales.

Para **quitar** un tag de varios contactos: seleccionarlos, elegir el tag y clic en **Quitar tag**. Solo se quita ese tag; los demás se conservan.

> La selección aplica a los contactos visibles en la página (máximo 50). Para más, repetir en cada página.

> Los tags no afectan el comportamiento de baja ni snooze. Son solo para clasificar.

---

## 5. Crear una campaña

1. Ir a **Campañas** → clic en **Nueva campaña**.
2. Llenar el formulario:
   - **Nombre**: identificador interno (ej. "Promo mayo semana 1").
   - **Canal**: elige **WhatsApp** o **SMS**. Ambos usan **plantilla** (no se escribe a mano):
     - **WhatsApp** → eliges una **plantilla aprobada** por Meta y llenas sus variables.
     - **SMS** → eliges una **plantilla SMS** (de las que creó el administrador en Plantillas →
       pestaña SMS). Verás la **vista previa** con el conteo de caracteres y segmentos
       (160 caracteres = 1 segmento). El texto ya no se escribe libre: así se garantiza que la
       campaña salga con una plantilla revisada (con su "STOP para baja").
   - **Destinatarios**: campo **obligatorio** y **no viene preseleccionado** (para evitar
     mandar a todos por error). Debes elegir a propósito: "Todos los contactos activos"
     (todos) o un **tag específico** (solo los contactos con esa etiqueta). Mientras no elijas
     destinatarios, el botón **Crear campaña** queda deshabilitado.
3. Clic en **Crear campaña**.

> ℹ️ La campaña queda en estado **borrador** hasta que se ejecute.

> ℹ️ En la lista de campañas, la columna **Canal / Plantilla** muestra un ícono según el canal
> (verde de WhatsApp o sobre azul de SMS). En WhatsApp además ves el nombre de la plantilla.

> ℹ️ **SMS y horario**: a diferencia de WhatsApp, el SMS **no tiene horario forzado**: tú
> decides cuándo ejecutarlo. Si lo haces entre 11PM y 7AM, el sistema muestra una advertencia
> (no un bloqueo): enviar de madrugada suele generar más bajas y que las operadoras filtren
> los mensajes. Es solo un aviso, tú decides.

> ℹ️ Al abrir una campaña en borrador verás su **alcance estimado**: cuántos contactos activos
> recibirían la campaña según el tag elegido. Es un estimado que se actualiza solo si importas o
> das de baja contactos; el número definitivo se confirma al ejecutar.

---

## 5.1. Ejecutar una campaña

1. En la lista de **Campañas**, abrir la campaña deseada.
2. Clic en **Ejecutar**.
3. El sistema verifica:
   - **WhatsApp**: que la plantilla siga aprobada, que haya un número activo y que el horario
     sea válido (lunes a viernes, 9AM–10PM hora México).
   - **SMS**: no valida horario (tú decides cuándo). El envío lo reparte automáticamente el
     gateway entre los teléfonos disponibles.
4. Si alguna condición falla, mostrará el motivo y no enviará nada.
5. Si todo está bien, los mensajes se encolan y se empiezan a procesar en segundo plano.

**El sistema protege automáticamente (en ambos canales):**
- No envía a contactos dados de baja o marcados como inválidos.
- Una baja cuenta para **los dos canales**: si alguien pidió baja por WhatsApp, tampoco recibe SMS.
- No envía a contactos en snooze (que respondieron "No por ahora" recientemente).
- **Dedup y cooldown son por canal, separados**: WhatsApp lleva su propio conteo y SMS el suyo.
  Un contacto que recibió WhatsApp hoy **sí** puede recibir un SMS hoy (y viceversa). Cada canal
  no reenvía al mismo contacto el mismo día ni dentro de su propio cooldown, pero no se cruzan.
- WhatsApp respeta el límite diario del número según el tier de Meta y el horario permitido.

> ⚠️ No ejecutar la misma campaña dos veces. Si necesitas reenviar, crear una nueva campaña.

---

## 6. Conversaciones — asignación de agentes

Cada conversación puede asignarse a un agente específico del equipo.

### Auto-asignación al llegar un mensaje

Cuando un contacto envía su primer mensaje, el sistema lo asigna automáticamente a un agente
según el **modo de asignación** configurado en **Configuración → Multi-agente**:

| Modo | Comportamiento |
|---|---|
| **Menos chats activos** (predeterminado) | Asigna al agente con menos conversaciones actualmente asignadas |
| **Primer disponible** | Asigna al primer agente activo en el sistema |

Si **no hay agentes activos**, la conversación queda sin asignar y aparece el chip
naranja **"Sin asignar"** en la lista. El admin u operador puede tomarla manualmente.

### Indicadores visuales en la lista de conversaciones

La lista separa **dos cosas**: el estado de la conversación y quién la atiende.

**Estado** (punto de color junto al nombre + chip):
- 🟢 **"Abierta"** (verde) — ventana de 24h abierta, se puede responder texto libre.
- ⚪ **"Cerrada"** (gris) — el contacto no ha respondido en las últimas 24h (solo plantilla reabre).
- 🟠 **"Snooze"** (ámbar) — el contacto pidió "No por ahora". **No bloquea el chat**: no entra a campañas de WhatsApp hasta la fecha (el SMS no se ve afectado, es por canal), pero le puedes seguir escribiendo a mano.
- 🔴 **"Baja"** (rojo) - dado de baja permanentemente, no se puede contactar.

**Asignación** (aparte del estado):
- Chip **"Sin asignar"** (ámbar) — nadie tiene esta conversación aún.
- Chip **"Tú"** (verde) + barra verde a la izquierda — es tuya.
- **Iniciales** del agente — la atiende otra persona (pasa el cursor para ver el nombre).

> El fondo tintado marca la conversación que tienes **abierta/seleccionada** (es distinto de la barra verde de "es tuya").

### Acciones de asignación manual

- **Tomar conversación**: cualquier agente puede hacer clic en este botón (panel derecho)
  para asignarse la conversación a sí mismo.
- **Asignar a agente** (solo admin y operador): usa el selector de agentes para reasignar
  la conversación a cualquier miembro del equipo.
- Los agentes solo ven las conversaciones que tienen asignadas.
  Los administradores y operadores ven todas.

> Si un agente ya no está disponible, el administrador puede reasignar la conversación
> a otro agente seleccionándolo en el selector.

### Cambiar el modo de asignación

Solo el administrador puede modificarlo: **Configuración → Multi-agente → Modo de asignación → Guardar**.

---

## 7. Ver estado de envíos

- En el **Dashboard**, tabla "Últimos mensajes", puedes ver el estado de cada envío:
  - `sent` — el mensaje salió de nuestro sistema hacia Meta.
  - `delivered` — Meta confirmó que llegó al celular del contacto.
  - `read` — el contacto abrió el mensaje.
  - `failed` — hubo un error. El administrador puede revisar el detalle.

- Para descargar un reporte completo en Excel: Dashboard → botón ↓ en "Últimos mensajes".

---

## 7. Conversaciones (respuestas entrantes)

Cuando un contacto responde al WhatsApp, el mensaje aparece en **Conversaciones**.

> ⚡ **En vivo:** si estás en Conversaciones, **todo** se actualiza solo sin recargar: las respuestas
> nuevas (con aviso "Nueva respuesta"), la asignación, el estado y los ✓ de entrega/leído de tus
> mensajes. (Requiere el servidor de tiempo real activo; si no lo está, se ve al reabrir, como siempre.)

1. Ir a **Conversaciones** en el menú lateral.
2. En el panel izquierdo ver la lista de contactos con mensajes. El estado (ver arriba):
   - **Abierta** (verde) — ventana de 24h abierta, puedes responder texto libre.
   - **Cerrada** (gris) — el contacto no ha respondido en las últimas 24h. Solo plantilla reabre.
   - **Snooze** (ámbar) — el contacto pidió "No por ahora". No entra a campañas de WhatsApp hasta la fecha (el SMS no se ve afectado), pero **sí le puedes escribir a mano** desde aquí.
   - **Baja** (rojo) - dado de baja permanentemente, no se puede contactar.

3. Seleccionar un contacto para ver el historial de mensajes.
4. Si la ventana está **abierta**, escribir en el campo de texto y presionar Enter (o el botón enviar).
5. Si la ventana está **cerrada**, el campo de texto aparece deshabilitado.
   Para retomar el contacto, crear una campaña con ese contacto y ejecutarla.

**Respuestas rápidas**: los chips en la parte inferior son atajos de mensajes frecuentes.
Clic en uno para cargar el texto automáticamente; luego clic en enviar.

> ℹ️ Los administradores pueden crear y eliminar respuestas rápidas desde el panel derecho.

---

## 7.1. Respuestas SMS

Los SMS que responden tus contactos aparecen en **Respuestas SMS** (menú lateral).

A diferencia de **Conversaciones** (WhatsApp), esto es una **lista de solo lectura**: se ven
las respuestas pero **no se contesta desde aquí** (el SMS es un canal de una sola vía en el panel).

Las respuestas vienen **agrupadas por contacto**: cada fila es un contacto, y si respondió varias
veces las verás juntas. Da **click en la fila** para desplegar todos sus mensajes en orden. Así, si
alguien mandó cinco SMS, no llenan la lista - es una sola fila que puedes abrir.

Cada fila (grupo) muestra:

- **Fecha** - cuándo llegó su **última** respuesta (hora de México).
- **Contacto** - el nombre y el celular que respondió.
- **Último mensaje** - el texto más reciente que envió.
- **Msgs** - cuántas respuestas mandó en total (se ven al abrir la fila).
- **Acción** - la etiqueta del grupo:
  - **"Interesado"** (verde) si respondió **SÍ** o **INFO** - es tu prospecto, dale seguimiento.
  - **"Baja automática"** (roja) si respondió **STOP** o **BAJA** - el sistema ya lo dio de baja de SMS.
  - Si mandó ambas, manda la **baja** (ya no se le puede escribir).

Herramientas:

- **Buscar** por número, nombre o texto del mensaje.
- Filtro **Todas / Interesados / Bajas** para ver solo los que dijeron que sí, solo las bajas, o todo.
- Botón **↻** para traer las respuestas más recientes (también entran solas en tiempo real).

> ℹ️ Aquí solo aparecen respuestas de tus **contactos**. Los SMS que recibe el chip del gateway
> que no son de un contacto (promociones o alertas de la operadora, códigos de otros servicios)
> **no se muestran** para no ensuciar la bandeja.

---

## 8. Baja - contactos que piden no recibir más

Cuando un contacto responde con cualquiera de estas palabras exactas:
**STOP, BAJA, CANCELAR, NO**

El sistema lo da de **baja** automáticamente y nunca más le envía mensajes.
Esto es irreversible - el cliente pidió explícitamente no recibir más mensajes.

- Los contactos dados de baja se pueden **ver** en la lista de Contactos (filtro "Baja") pero no se eliminan de la base de datos (auditoría).
- El botón **Dar de baja** marca al contacto manualmente (baja manual, mismo efecto).

---

## 9. Exportar reportes

| Reporte         | Cómo acceder                                          | Columnas incluidas                              |
|-----------------|-------------------------------------------------------|-------------------------------------------------|
| Contactos       | Contactos → botón **Exportar Excel**                  | Teléfono, Nombre, Estado, Snooze, Fecha alta    |
| Mensajes        | Dashboard → botón ↓ en "Últimos mensajes"             | Destino, Plantilla, Estado, Número, Fecha envío |

---

## 10. Preguntas frecuentes

**¿Por qué no puedo ejecutar una campaña fuera de horario?**
El sistema bloquea los envíos fuera de 9AM–10PM hora México (lunes a viernes).
Esto es un requisito para no violar las políticas de Meta y evitar reportes de spam.

**¿Por qué el número aparece "PAUSADO"?**
Meta detectó un volumen inusual o reportes de spam. El sistema pausó los envíos automáticamente
para proteger la cuenta. El administrador debe revisar antes de reanudar.

**¿Se puede enviar a un número que no tiene WhatsApp?**
No. Los números sin WhatsApp se marcan automáticamente como "inválidos" tras el primer intento fallido y no se vuelven a intentar.

**¿Cuántos mensajes se pueden enviar por día?**
Depende del tier del número WhatsApp:
- Tier 1 (inicio): hasta 250 conversaciones/día
- Tier 2: hasta 1,000/día (sube automáticamente al llegar al límite con buena calidad)
- Tier 3: hasta 10,000/día

El sistema controla esto automáticamente — el operador no puede cambiarlo.

**¿Qué es el "snooze"?**
Si un contacto toca el botón "No por ahora" en una plantilla, el sistema lo pausa
por el período configurado (por defecto 30 días). Pasado ese período, vuelve a estar
disponible para campañas automáticamente. **El snooze solo pausa las campañas de WhatsApp**
(el SMS es un canal aparte y no se ve afectado): en Conversaciones le puedes seguir
escribiendo a mano si hace falta darle seguimiento.

---

## 11. Guías operacionales Meta

Esta sección aplica principalmente al **administrador del sistema**. El operador normal
no necesita realizar estas acciones en el día a día, pero es útil conocerlas para entender
qué está pasando si algo falla.

---

### 11.A. Agregar un número de prueba en sandbox

**Cuándo se necesita:** un miembro del equipo no recibe los mensajes de prueba enviados
desde el panel. Esto ocurre porque Meta (en cuenta sandbox) solo permite enviar a números
que han sido registrados y verificados previamente.

**Pasos:**

1. Ir a [developers.facebook.com](https://developers.facebook.com) e iniciar sesión con la cuenta del administrador de Meta.
2. Seleccionar la aplicación del proyecto (`wa-api-test` o el nombre que corresponda).
3. En el menú lateral izquierdo: **WhatsApp → Configuración de API**.
4. Desplazarse a la sección **"Números de teléfono de destinatario"**.
5. Clic en **"Agregar número de teléfono"**.
6. Ingresar el número en formato internacional (ejemplo: `+52 923 131 1146`).
7. El número recibirá un código OTP por WhatsApp. Ingresarlo en el campo que aparece en pantalla.
8. Una vez verificado, el número aparece en la lista y ya puede recibir mensajes de prueba.

> ⚠️ Esta restricción es solo en la cuenta sandbox (desarrollo). En la cuenta de producción del cliente,
> se puede enviar a cualquier número sin este requisito adicional.

---

### 11.B. Renovar el System User Token

**Cuándo puede ocurrir:** el token de acceso normalmente **no expira** (es un System User Token permanente).
Sin embargo, puede invalidarse si alguien revoca manualmente los permisos del System User en Business Manager,
o si se cambia la contraseña maestra de la cuenta Meta asociada.

**Señal de alerta:** el panel muestra errores en los envíos con código `467` (token expirado).

**Pasos para generar un token nuevo:**

1. Ir a [business.facebook.com](https://business.facebook.com) → **Configuración del negocio** (ícono de engranaje).
2. En el menú izquierdo: **Usuarios → Usuarios del sistema**.
3. Seleccionar el usuario del sistema `waclouddev`.
4. Clic en **"Generar nuevo token"**.
5. Seleccionar la app (`wa-api-test`) y marcar los permisos:
   - `whatsapp_business_messaging`
   - `whatsapp_business_management`
6. Copiar el token generado (aparece solo una vez — guardarlo de inmediato).

**Pegar el token nuevo en el panel:**

- Opción A — Panel web: ir a **Configuración** en el menú del sistema → campo "Token de acceso" → pegar → Guardar.
- Opción B — Comando artisan (en el servidor): `php artisan wa:update-token TOKEN_AQUI`

> El token nunca se ve completo en el panel por seguridad — solo los últimos 4 caracteres confirman que se guardó.

---

### 11.C. Interpretar alertas en Business Manager

Meta muestra alertas en [business.facebook.com](https://business.facebook.com) cuando algo requiere atención.
Esta tabla explica qué significa cada una y qué hacer:

| Alerta en Business Manager | Qué significa | Qué hacer |
|---|---|---|
| **Calidad del número: Baja (rojo)** | Muchos usuarios bloquearon el número o reportaron spam | El sistema ya pausó los envíos automáticamente. No reanudar hasta que la calidad suba a Medium. Revisar con el administrador qué campañas recientes pudieron causar el problema. |
| **Calidad del número: Media (amarillo)** | Algunos bloqueos o mensajes no leídos. Calidad en riesgo | Continuar con precaución. Reducir volumen de envíos si la tendencia es a la baja. |
| **Límite de mensajes alcanzado** | Se llegó al tope diario del tier actual | Normal — el sistema respeta este límite. Los mensajes pendientes se encolan para el día siguiente automáticamente. |
| **Cuenta en revisión / Restricción temporal** | Meta está revisando la actividad de la cuenta | **Detener todos los envíos de inmediato.** Contactar al administrador del sistema. No intentar enviar mientras dure la revisión. |
| **Plantilla rechazada** | Meta no aprobó una plantilla sometida a revisión | El rechazo de una plantilla no afecta la cuenta. Solo significa que esa plantilla no puede usarse. Revisar el motivo en la sección Plantillas de Business Manager y modificar el contenido antes de volver a someter. |
| **Actualización de políticas** | Meta cambió sus términos de uso | Leer el aviso y aceptar si corresponde. El administrador técnico revisa si hay cambios que afecten el sistema. |
| **Token expirado / Permiso revocado** | El acceso a la API fue cortado | Seguir los pasos de la sección 11.B para renovar el token. |

> Si aparece una alerta no listada aquí, tomar nota del mensaje exacto y contactar al administrador
> antes de tomar cualquier acción.

---

### 11.D. Registrar un número nuevo de WhatsApp

**Cuándo se hace:** cuando el negocio decide agregar un número adicional para aumentar la capacidad
de envíos, o para reemplazar un número con calidad degradada.

**Requisitos previos (gestionados por el administrador):**

- SIM nueva dedicada exclusivamente a WhatsApp (Telcel o AT&T recomendados).
  **Nunca usar el número oficial del negocio.**
- El número debe poder recibir llamadas o SMS para la verificación inicial de Meta.
- El administrador registra el número en [business.facebook.com](https://business.facebook.com) → **Cuentas de WhatsApp → Números de teléfono → Agregar número**.

**Lo que hace el operador en el panel:**

1. Ir a **Configuración → Números de WhatsApp**.
2. El número nuevo aparecerá en la lista una vez que el administrador lo haya registrado en Meta.
3. No es necesario hacer nada más — el sistema inicia el warm-up automáticamente.

**Qué es el warm-up (para entender qué pasa en las primeras semanas):**

El número empieza con un límite bajo (≈ 250 mensajes/día). El sistema lo sube gradualmente
de forma automática a medida que los envíos tienen buena calidad y no generan reportes de spam.
Este proceso tarda entre 2 y 4 semanas en alcanzar capacidad plena.

El operador **no puede acelerar el warm-up** ni cambiar los límites manualmente — el sistema
lo controla para proteger la cuenta.

> ℹ️ Durante el warm-up, el semáforo del número puede aparecer en amarillo ("calidad pendiente").
> Esto es normal en los primeros días con un número nuevo.

---

### 11.E. Verificar el negocio en Meta (Business Verification)

**Qué es:** Meta requiere verificar que el negocio es legítimo antes de permitir envíos en producción
a gran escala. Sin esta verificación la cuenta queda limitada a 250 mensajes/día en total.

**Importante — hay 3 tipos de "verificado" en Meta y no son lo mismo:**

| Tipo | Dónde se ve | ¿Sirve para WhatsApp API? |
|---|---|---|
| Cuenta personal de FB verificada | Perfil personal | No |
| Página de FB verificada (palomita azul) | Fan page pública | No |
| **Meta Business Manager verificado** | Business Manager → Configuración → Información del negocio | **Sí, esta es la que se necesita** |

**Cómo confirmar si ya está hecha:**

1. Ir a [business.facebook.com](https://business.facebook.com).
2. **Configuración del negocio → Información del negocio**.
3. Buscar el campo **"Verificación del negocio"**:
   - ✅ Dice **"Verificado"** con palomita verde → ya está lista, no se necesita subir documentos.
   - ❌ Dice **"Sin verificar"** o **"Verificación pendiente"** → falta completarla, aunque la página o cuenta personal estén verificadas.

**Si falta verificar — qué documentos pide Meta (México):**

- **RFC** — el más común y generalmente suficiente.
- O Acta Constitutiva / Cédula de Identificación Fiscal.

El administrador sube los documentos directamente en Business Manager. Meta tarda **3 a 10 días hábiles** en aprobar.

> ⚠️ Esta verificación se hace una sola vez. Una vez aprobada, aplica para todos los números
> de WhatsApp y aplicaciones bajo ese Business Manager.
