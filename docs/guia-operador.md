# Guía del Operador — Prestamaz Panel

> Para el equipo de Prestamaz. No se requiere conocimiento técnico.
> Si algo no funciona como se describe aquí, contactar al administrador del sistema.

---

## 1. Acceso al panel

1. Abrir el navegador y entrar a la URL del sistema (el administrador te la proporcionará).
2. Ingresar con tu correo y contraseña.
3. Si olvidaste tu contraseña, pedirle al administrador que la restablezca.

**Roles disponibles:**
- **Administrador** — acceso total (crear campañas, configurar plantillas, ver configuración)
- **Operador** — puede cargar contactos, crear y ejecutar campañas, ver reportes
- **Agente** — solo puede ver y responder conversaciones entrantes

---

## 2. Dashboard

Al entrar verás el dashboard con:

- **Métricas de mensajes**: enviados, entregados, leídos y fallidos (totales acumulados).
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
- **Últimos mensajes**: tabla de envíos recientes con su estado. Puedes filtrar por estado
  (enviados / entregados / leídos / fallidos) y navegar entre páginas con los controles de paginación.

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

5. Los contactos con **opt-out** (que pidieron baja anteriormente) nunca reaparecen aunque se vuelvan a importar.

> 💡 Para exportar los contactos actuales a Excel, usar el botón **Exportar** en la pantalla de Contactos.

### Agregar un contacto manualmente (sin Excel)

Para cuando consigues un prospecto suelto (referido, llamada, walk-in):

1. En **Contactos**, clic en **Agregar contacto**.
2. Escribir el teléfono. Al teclearlo, el sistema **verifica el número al instante** y muestra un aviso:
   - ✅ **Disponible** — se puede agregar.
   - ⚠️ **Ya existe** — muestra su estado (activo, opt-out, inválido, inalcanzable). No se puede volver a agregar (se conserva el registro original).
   - ❌ **Formato inválido** — debe ser México: 52 + 10 dígitos.
3. Escribir el nombre (opcional) y clic en **Guardar**.

> El botón Guardar solo se activa si el número es válido y no existe. Un número dado de baja (opt-out) no se puede reincorporar por esta vía.

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

> Los tags no afectan el comportamiento de opt-out ni snooze. Son solo para clasificar.

---

## 5. Crear una campaña

1. Ir a **Campañas** → clic en **Nueva campaña**.
2. Llenar el formulario:
   - **Nombre**: identificador interno (ej. "Promo mayo semana 1").
   - **Plantilla**: solo aparecen las plantillas aprobadas por Meta. No se puede escribir una a mano.
   - **Destinatarios**: elige "Todos los contactos activos" o filtra por un tag específico.
     Si seleccionas un tag, solo los contactos con esa etiqueta recibirán la campaña.
   - **Variables de la plantilla**: si la plantilla tiene `{{Nombre}}` u otras variables, aparecerán
     campos para capturar los valores que se enviarán a todos los contactos.
3. Clic en **Guardar**.

> ℹ️ La campaña queda en estado **borrador** hasta que se ejecute.

> ℹ️ Al abrir una campaña en borrador verás su **alcance estimado**: cuántos contactos activos
> recibirían la campaña según el tag elegido. Es un estimado que se actualiza solo si importas o
> das de baja contactos; el número definitivo se confirma al ejecutar.

---

## 5.1. Ejecutar una campaña

1. En la lista de **Campañas**, abrir la campaña deseada.
2. Clic en **Ejecutar**.
3. El sistema verifica:
   - Que la plantilla siga aprobada.
   - Que haya un número activo.
   - Que el horario sea válido (lunes a viernes, 9AM–10PM hora México).
4. Si alguna condición falla, mostrará el motivo y no enviará nada.
5. Si todo está bien, los mensajes se encolan y se empiezan a procesar en segundo plano.

**El sistema protege automáticamente:**
- No envía a contactos con opt-out o marcados como inválidos.
- No envía a contactos en snooze (que respondieron "No por ahora" recientemente).
- Respeta el límite diario del número WhatsApp según el tier de Meta.
- Solo envía dentro del horario permitido (9AM–10PM, L-V).

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

- Barra verde a la izquierda — conversación asignada al agente que está viendo la pantalla.
- Chip **"Sin asignar"** (naranja) — nadie tiene esta conversación asignada aún.
- Chip **"Activa"** (verde) — ventana de 24h abierta, se puede responder.
- Chip **"Cerrada"** (gris) — el contacto no ha respondido en las últimas 24h.
- Chip **"Snooze"** (naranja) — el contacto pidió "No por ahora".
- Chip **"Baja"** (rojo) — opt-out permanente.

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

1. Ir a **Conversaciones** en el menú lateral.
2. En el panel izquierdo ver la lista de contactos con mensajes. Los tags indican el estado:
   - **Activa** (verde) — ventana de 24h abierta, puedes responder texto libre.
   - **Cerrada** (gris) — el contacto no ha respondido en las últimas 24h.
     Solo se puede reabrir enviando una plantilla desde Campañas.
   - **Snooze** (naranja) — el contacto pidió "No por ahora", se reactivará automáticamente.
   - **Baja** (rojo) — opt-out permanente, no se puede contactar.

3. Seleccionar un contacto para ver el historial de mensajes.
4. Si la ventana está **abierta**, escribir en el campo de texto y presionar Enter (o el botón enviar).
5. Si la ventana está **cerrada**, el campo de texto aparece deshabilitado.
   Para retomar el contacto, crear una campaña con ese contacto y ejecutarla.

**Respuestas rápidas**: los chips en la parte inferior son atajos de mensajes frecuentes.
Clic en uno para cargar el texto automáticamente; luego clic en enviar.

> ℹ️ Los administradores pueden crear y eliminar respuestas rápidas desde el panel derecho.

---

## 8. Opt-out — contactos que piden baja

Cuando un contacto responde con cualquiera de estas palabras exactas:
**STOP, BAJA, CANCELAR, NO**

El sistema lo marca automáticamente como **opt-out** y nunca más le envía mensajes.
Esto es irreversible — el cliente pidió explícitamente no recibir más mensajes.

- Los contactos con opt-out se pueden **ver** en la lista de Contactos (filtro "Opt-out") pero no se eliminan de la base de datos (auditoría).
- El botón **Eliminar** en un contacto activo también lo marca como opt-out (baja manual).

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
disponible para campañas automáticamente.

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
