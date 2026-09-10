# Guía de uso - Panel Prestamaz

> Para todo el equipo de Prestamaz. No necesitas saber nada técnico.
> Aquí aprendes a usar el panel día a día: enviar campañas, atender respuestas,
> cuidar la base de contactos y entender por qué el sistema hace lo que hace.
>
> Para temas de Facebook/Meta (plantillas, números, permisos) hay una guía aparte.

---

## Cómo leer esta guía

- Cada quien ve en el panel solo lo que le toca según su usuario. Esta guía cubre todas las
  pantallas; si alguna no te aparece, es porque tu rol no la usa (ver [tu rol](#3-que-me-toca-a-mi-segun-tu-rol)).
- **Soporte** o **administrador del sistema** = la persona que configura el sistema por
  dentro (hoy, quien lo instaló y lo mantiene). Cuando la guía dice "avisa a soporte", es a
  esa persona.
- El ícono **?** que aparece junto a varios títulos dentro del panel abre una ayuda corta de
  esa pantalla. Úsalo cuando tengas una duda rápida.

---

## Índice

1. [¿Qué es este panel?](#1-que-es-este-panel)
2. [Entrar al panel](#2-entrar-al-panel)
3. [¿Qué me toca a mí? (según tu rol)](#3-que-me-toca-a-mi-segun-tu-rol)
4. [El Panel (pantalla de inicio)](#4-el-panel-pantalla-de-inicio)
5. [La campana de avisos](#5-la-campana-de-avisos)
6. [Contactos](#6-contactos)
7. [Etiquetas (tags)](#7-etiquetas-tags)
8. [Campañas](#8-campanas)
9. [Conversaciones y agentes](#9-conversaciones-y-agentes)
10. [Respuestas SMS](#10-respuestas-sms)
11. [Bajas: contactos que piden no recibir más](#11-bajas-contactos-que-piden-no-recibir-mas)
12. [Control de envíos: las reglas que te protegen](#12-control-de-envios-las-reglas-que-te-protegen)
13. [Reportes en Excel](#13-reportes-en-excel)
14. [¿Qué hago si...? (cómo reaccionar)](#14-que-hago-si-como-reaccionar)
15. [Preguntas frecuentes](#15-preguntas-frecuentes)

---

## 1. ¿Qué es este panel?

Es la herramienta para enviar **mensajes masivos** a prospectos, por dos vías:

- **WhatsApp** - mensajes con plantilla aprobada e imagen de marca.
- **SMS** - mensajes de texto normales.

Sirve para **promocionar los préstamos**: mandas un mensaje a muchos contactos, quien
responde "Sí me interesa" pasa a un agente que le da seguimiento hasta la sucursal.

**Lo importante:** el panel te protege solo. No te deja cometer errores que dañen la cuenta
(enviar fuera de horario, mandar a quien pidió baja, saturar a un contacto). Si algo se ve
"bloqueado", casi siempre es el sistema cuidándote, no un error. La sección
[Control de envíos](#12-control-de-envios-las-reglas-que-te-protegen) explica cada regla y por qué existe.

---

## 2. Entrar al panel

1. Abre el navegador y entra a la dirección del sistema (soporte te la da).
2. Escribe tu **correo** y tu **contraseña**.
3. Clic en **Entrar**.

> **¿Olvidaste tu contraseña?** El login no tiene un botón para recuperarla solo. Pídele al
> administrador que te ponga una nueva: en **Usuarios**, junto a tu nombre, hay un botón
> **Contraseña** (🔑) que abre una ventana para escribir una nueva. La anterior deja de
> funcionar de inmediato. Es a propósito: así nadie cambia contraseñas por su cuenta.

> Cada persona tiene su propio usuario. No compartas tu contraseña.

---

## 3. ¿Qué me toca a mí? (según tu rol)

Cada persona ve solo lo que necesita. Busca tu rol:

| Tu rol | Qué puedes ver y hacer |
|---|---|
| **Administrador** | Todo: contactos, campañas, respuestas SMS, plantillas, usuarios y conversaciones. Configura el sistema. |
| **Operador** | Panel, contactos, campañas, respuestas SMS y conversaciones (todas). No gestiona plantillas ni usuarios. |
| **Agente** | **Solo conversaciones**, y solo las que le asignaron. Al entrar cae directo ahí. No ve el resto del panel. |

> **Si eres agente**, tu guía es corta: entra directo a
> [Conversaciones y agentes](#9-conversaciones-y-agentes). El resto de las pantallas no te
> aparecen, así que no tienes que leerlas.

---

## 4. El Panel (pantalla de inicio)

Al entrar ves un resumen de todo. De arriba a abajo:

- **Mensajes**: cuántos se han enviado, entregado, leído y fallado (totales).
- **Contactos**: total, activos, bajas, inválidos e inalcanzables.
  - Un **inalcanzable** es un contacto al que le mandamos **3 mensajes seguidos que no llegaron**
    (sin que ninguno se entregara en medio). Probablemente bloqueó el número o ya no existe. El
    sistema lo saca de las campañas para cuidar la calidad. El administrador lo puede reactivar
    si el contacto vuelve a escribir. *(Si en medio de esos 3 le llega uno, la cuenta se reinicia.)*
- **Meta mensual**: una barra que muestra cuánto llevas del objetivo del mes. Colores:
  **Azul** = buen ritmo · **Amarillo** = vas por debajo · **Rojo** = muy por debajo ·
  **Verde** = meta alcanzada. El administrador cambia la meta en Configuración.
- **Gráfica de 14 días**: barras por día con enviados, entregados, leídos y fallidos.
- **Salud del número**: un semáforo (verde / amarillo / rojo) que dice cómo está el número de
  WhatsApp, cuántos mensajes van hoy y si está pausado.
- **Últimos mensajes**: tabla con los envíos recientes y su estado. Mezcla WhatsApp y SMS; la
  columna **Canal** los distingue con un ícono. La columna **Motivo** dice por qué falló cuando
  un mensaje no llegó.

**Botones de esta pantalla:**
- **↺ (refrescar)** - actualiza la gráfica y los números. Úsalo si acabas de enviar una campaña.
- **↓ (descargar)** en "Últimos mensajes" - baja el reporte de mensajes a Excel.
- **Filtro de estado** - muestra solo enviados / entregados / leídos / fallidos.
- **Enviar mensaje de prueba** *(solo administrador)* - prueba una plantilla con un contacto
  antes de lanzar la campaña. El operador no ve este botón.

> **La prueba es un mensaje real.** Sale por el número de la empresa igual que una campaña:
> gasta cupo del día, Meta lo cobra en la factura de la cuenta, y el contacto queda en
> **enfriamiento**, o sea que no podrá recibir campañas hasta que pase ese periodo. Usa
> siempre el mismo número de pruebas, y que no esté en los destinatarios de tus campañas reales.
> A quien pidió su baja el sistema no le envía ni siquiera una prueba.

> ⚠️ Si el semáforo está en **ROJO** o dice **PAUSADO**, NO ejecutes campañas. El sistema se
> pausó solo para proteger la cuenta. Avisa a soporte. (Ver
> [¿Qué hago si...?](#14-que-hago-si-como-reaccionar))

### Qué significa el estado de cada mensaje

En "Últimos mensajes" (y en el detalle de una campaña) cada mensaje tiene un estado:

- **Enviados / En tránsito** - salió de nuestro sistema, todavía no hay confirmación de que
  llegó al celular.
- **Entregados** - llegó al celular del contacto.
- **Leídos** - el contacto abrió el mensaje (solo WhatsApp).
- **Fallidos** - no se pudo entregar (el número no existe, no tiene WhatsApp, o hubo un bloqueo).

> Es normal que un mensaje pase de Enviado a Entregado a Leído con unos minutos de diferencia.
> Si estás en la pantalla, se actualiza solo.

### Por qué falló un mensaje

La columna **Motivo**, al lado del estado, explica en español qué pasó. Pasa el cursor encima
para ver el texto completo. Hay dos clases de motivo, y la diferencia importa:

| Clase | Qué significa | Ejemplo |
|---|---|---|
| **Lo dijo el proveedor** | El mensaje sí salió y WhatsApp (o la compañía de SMS) lo rechazó | "Meta respondió: El destinatario alcanzó su límite de mensajes de marketing." |
| **Lo decidió el sistema** | El mensaje **nunca salió**: el panel lo frenó para protegerte | "En enfriamiento: se le envió hace poco." |

Los del proveedor salen en rojo y siempre dicen quién lo dijo - **Meta** para WhatsApp, **el
gateway de SMS** para SMS. Los que decidió el sistema salen en naranja.

> Un motivo del proveedor **no siempre es un problema del número**. El más común, el límite de
> mensajes de marketing, es un tope que Meta le pone a cada persona sumando lo que recibe de
> todas las empresas: no hay nada que arreglar de nuestro lado. Si ves muchos fallos seguidos de
> otro tipo, avisa a soporte antes de lanzar otra campaña.

El Excel de mensajes trae esa misma columna **Motivo**, con el texto completo.

---

## 5. La campana de avisos

Arriba a la derecha hay una **campana** 🔔. Cuando algo necesita tu atención, aparece un
número rojo con la cantidad de avisos nuevos.

Al hacer clic se abre una lista con los últimos avisos. Los no leídos salen con fondo azul; al
abrir la lista se marcan como leídos.

**Tipos de aviso:**
- **Entrega fallida** - un mensaje no llegó. Te dice el motivo en palabras simples. Si ves
  muchos seguidos, revisa la **Salud del número** en el Panel.
- **Problema con los SMS** - el sistema manda SMS pero no recibe de vuelta las confirmaciones.
  Avisa a soporte. Se limpia solo cuando vuelve a funcionar.

> **Por qué existe:** para que te enteres de un problema sin tener que revisar tabla por tabla.
> No tienes que actuar en cada aviso, son informativos. Si un mensaje falló por un bloqueo
> temporal, el sistema lo reintenta solo cuando se levanta.

---

## 6. Contactos

Aquí vive tu base de prospectos. Entra a **Contactos** en el menú.

### Importar desde Excel

Sirve para dos cosas: **dar de alta** contactos nuevos y, sobre todo, **etiquetar en masa**
contactos que ya tienes.

1. Clic en **Importar Excel**.
2. Sube el archivo `.xlsx`, `.xls` o `.csv`:

   | Teléfono | Nombre (opcional) | Etiqueta (opcional) |
   |----------|-------------------|---------------------|
   | 9231311146 | Juan Pérez | VIP |
   | 6692522844 | Ana López | VIP, Mazatlán |

   - Los teléfonos pueden ir con clave de país (`529231311146`) o solo los 10 dígitos.
   - **Con encabezado el orden de las columnas da igual**, el sistema las reconoce.
   - La columna de etiqueta se puede llamar `etiqueta`, `etiquetas`, `tag` o `tags`.
   - **Varias etiquetas en una celda** separadas por coma.
   - Las etiquetas que no existan **se crean solas**. `VIP` y `vip` son la misma.
3. Al terminar verás el resumen: **nuevos / ya existían / inválidos**, y si el archivo traía
   columna de etiqueta, cuántas se asignaron y cuántas se crearon.

> 🏷️ **Si el teléfono ya existe, NO se duplica: se le agrega la etiqueta.** Por eso puedes
> re-subir una lista de números que ya tienes solo para clasificarlos. Las etiquetas que el
> contacto ya tenía **no se borran**, se suman.

Detalles:

- **Ya existían**: el número ya estaba. Se etiqueta pero no se da de alta otra vez.
- **Inválidos**: números mal escritos. Se ignoran y te dice en qué fila estaban.
- Los contactos que pidieron **baja** nunca regresan, aunque los vuelvas a importar.
- Un contacto **eliminado** no se re-etiqueta (está fuera de listas y campañas).

> 💡 El botón **Exportar** baja tus contactos a Excel **con su columna de etiquetas**, en el
> mismo formato que lee el importador. Puedes exportar, cambiar etiquetas en Excel y volver a
> subir el archivo.

### Agregar un contacto a mano

Para un prospecto suelto (un referido, una llamada):

1. Clic en **Agregar contacto**.
2. Escribe **solo los 10 dígitos** del celular (sin 52 ni +). El sistema pone el resto. Al
   terminar te avisa al instante:
   - ✅ **Disponible** - se puede agregar.
   - ⚠️ **Ya existe** - te dice en qué estado está.
   - ❌ **Deben ser 10 dígitos** - faltan o sobran números.
3. Escribe el nombre (opcional) y clic en **Guardar**. El botón solo se activa si el número es
   válido y no existe.

### Buscar muchos números de golpe (pegar una lista)

Cuando traes una lista de números en Excel y quieres ver solo esos:

1. Copia la columna de números en Excel.
2. Pégala **directo en el buscador** de Contactos. Al detectar más de un número, el panel
   entiende que es una lista y filtra por ella (no la trata como texto de búsqueda).
   - También puedes usar el botón **Pegar lista**, que abre un recuadro grande para pegar.
3. Arriba de la tabla aparece el resumen del pegado:
   - **Cuántos números** estás filtrando.
   - **Cuántos no están dados de alta** en el sistema, con el botón **Copiar faltantes**
     para llevártelos y darlos de alta o revisarlos.
   - **Cuántos tenían formato inválido** (no se pudieron leer como teléfono).
4. Para volver a la lista completa, clic en **Quitar filtro**.

Detalles útiles:

- Sirve pegar separado por **salto de línea, tabulador, coma o espacio**. También acepta
  números con `+`, guiones o paréntesis, y con solo 10 dígitos (el sistema pone el 52).
- Los números repetidos se cuentan **una sola vez**.
- Se pueden pegar hasta **5,000 números** de una vez.
- El pegado **manda sobre el buscador de texto**: si hay lista pegada, el texto se ignora.
  Los demás filtros (estado, etiqueta, entregabilidad) **sí** se aplican encima de la lista.

### Filtrar por entregabilidad

El selector **Entregabilidad** busca por el estado de la columna del mismo nombre, y siempre
dice **de qué canal** habla, porque cada canal lleva su propia cuenta:

| WhatsApp | SMS |
|---|---|
| Disponible - WhatsApp | Disponible - SMS |
| Enviado hoy - WhatsApp | Enviado hoy - SMS |
| Enfriamiento - WhatsApp | Enfriamiento - SMS |
| Pospuesto - WhatsApp | - |
| En espera (Meta) - WhatsApp | - |
| No recibe - WhatsApp | No recibe - SMS |

> ⚠️ **Enfriamiento - WhatsApp** no es lo mismo que **Enfriamiento - SMS**. Un contacto puede
> estar en enfriamiento de SMS y disponible en WhatsApp al mismo tiempo. **Pospuesto** y
> **En espera (Meta)** solo existen en WhatsApp.

Se pueden **marcar varios estados a la vez** y se suman. Ejemplos:

- **Enviado hoy - WhatsApp** + **Enfriamiento - WhatsApp** → todos los que ahorita no reciben
  WhatsApp por haberlo recibido hace poco.
- **Disponible - WhatsApp** + **Disponible - SMS** → los que reciben por cualquiera de los dos canales.

El filtro se combina con los demás (estado, etiqueta, lista pegada): esos se aplican encima.

Uso típico: antes de lanzar una campaña de WhatsApp, filtra **Disponible - WhatsApp** para ver
a cuántos les va a llegar de verdad.

### Cuántos registros ver por página

Debajo de cada tabla hay un selector **Mostrar**: `10`, `20`, `50`, `100`, `250`, `500` y `Todos`.
Sirve igual en Contactos, Campañas, Respuestas SMS y la lista de mensajes del Panel.

- Al cambiar el número, la tabla vuelve a la **página 1**.
- **Todos** trae hasta **5,000 registros**. Si tu filtro da más, verás el aviso
  *"Mostrando 5,000 de N - afina el filtro o usa Exportar"*: el navegador no aguanta dibujar
  cientos de miles de filas de golpe. Para llevarte la lista completa usa **Exportar (.xlsx)**.
- Con listas grandes (500 o más) la tabla tarda un poco más en dibujarse. Es normal.

### Los estados de un contacto

La tabla tiene dos columnas de estado. El ícono **?** junto al título las explica.

**Estado** (quién es el contacto):
- **Activo** - normal.
- **Baja** - pidió no recibir más.
- **Inválido** - el número no tiene WhatsApp.
- **Inalcanzable** - le mandamos 3 mensajes seguidos que no llegaron (probablemente bloqueó el
  número). El administrador lo puede reactivar.

**Entregabilidad** (si le llega ahora mismo). Se muestra **por canal**, con dos etiquetas: una
de WhatsApp y otra de SMS. Cada canal cuenta por su lado, así que un contacto puede estar
disponible en uno y en pausa en el otro:
- 🟢 **Disponible** - se le puede enviar.
- ⚪ **Pospuesto** - pidió "No por ahora", no se le manda hasta la fecha.
- 🟠 **Enfriamiento** - recibió hace poco, se espera un tiempo para no saturarlo.
- 🟠 **En espera (Meta)** - el contacto llegó a su tope de mensajes de marketing y WhatsApp pide
  esperar 24 horas. Al pasar el tiempo vuelve solo a Disponible. Ver abajo.
- 🔵 **Enviado hoy** - ya recibió hoy, no se le reenvía el mismo día.
- 🔴 **No recibe** - bloqueado en ese canal (baja / inválido / inalcanzable).

> **"En espera (Meta)" no es culpa nuestra ni del número.** WhatsApp le pone un tope a **cada
> persona** de cuánta publicidad recibe, sumando **todas las empresas** que le escriben, no solo
> nosotros. Cuando alguien llega a su tope, ese mensaje no se entrega y WhatsApp pide esperar
> 24 horas. El sistema lo respeta solo: si lo metes en una campaña antes, lo descarta. **Insistir
> antes de tiempo es contraproducente** - WhatsApp lo bloquea 24 horas más.

> Un contacto puede estar **Activo** pero **Pospuesto**, en **Enfriamiento** o **Enviado hoy**. Es
> normal - solo significa que ahorita no se le envía para no saturarlo. (Ver
> [Control de envíos](#12-control-de-envios-las-reglas-que-te-protegen).)

**Chip rojo "Baja SMS"**: aparece cuando el contacto no recibe SMS (pidió baja por SMS o el
número no recibe texto). Es aparte del estado: puede recibir WhatsApp pero no SMS.

**Cartera**: cómo está esa persona en el sistema de Prestamaz. No lo escribe nadie a mano: el
panel lo trae solo cada noche, así que siempre dice cómo está **hoy**.

| Cartera | Qué significa |
|---|---|
| **LIQUIDADO** | Ya pagó su crédito. Es el mejor prospecto para ofrecerle uno nuevo. |
| **ACTIVO** | Tiene un crédito vigente ahorita. |
| **BURÓ** | Está reportado en buró de crédito. |
| **BAJA** | Terminó su relación con Prestamaz. |

Si mañana aparece una clasificación nueva, sale sola en el filtro: la lista no está fija.

> ⚠️ **La "BAJA" de Cartera NO es nuestra Baja.** En el sistema del cliente, "BAJA" significa que
> esa persona dejó de ser su cliente. **Sí se le puede mandar publicidad.** Nuestra Baja - la que
> significa "pidió no recibir más" - vive en la columna **Estado**, y ésa nunca se toca.

### Mandarle una campaña a un grupo de la cartera

El caso típico: mandarle una promoción de renovación solo a los que ya liquidaron su crédito.

1. En **Contactos**, en el desplegable **Cartera: todos**, elige `LIQUIDADO`.
2. La lista se filtra. Abajo del buscador aparece un aviso:
   *"¿Quieres etiquetar los N contactos del filtro, no solo los de esta página?"*
3. Elige una etiqueta (o créala antes, por ejemplo `Renovación septiembre`) y da clic en
   **Etiquetar todo lo filtrado**.
4. El panel te dice **a cuántos** les va a poner la etiqueta y te pide confirmar.
5. Ve a **Campañas**, crea la campaña y elige esa etiqueta en **Destinatarios**.

**Por qué son dos pasos y no uno:** la Cartera cambia sola cada noche. Si alguien liquida mañana,
deja de ser BURÓ. La etiqueta, en cambio, se queda fija: es tu registro de **a quién le mandaste**
esa campaña, aunque después cambie su situación.

> El botón etiqueta **todos los contactos del filtro**, no solo los de la página que estás viendo.
> Si quieres etiquetar unos pocos, marca sus casillas y usa **Asignar tag** (la barra azul).
> Ninguno de los dos quita las etiquetas que el contacto ya tuviera.

**Botones de la fila de un contacto:**
- 🏷️ **Etiqueta** - le pones o quitas tags (ver [Etiquetas](#7-etiquetas-tags)).
- **Dar de baja** (rojo) - lo marca como baja (ver abajo).
- 🗑 **Eliminar** (solo administrador) - lo borra de listas. Solo para basura o pruebas.

### Dar de baja NO es lo mismo que Eliminar

- **Dar de baja** (admin u operador) - el contacto **pidió baja**. Se marca y nunca más se le
  envía. Se queda guardado para tener registro. **Por qué:** es un requisito legal, hay que
  poder demostrar que respetamos la baja.
- **Eliminar** (solo administrador) - **limpieza** de basura o números de prueba. Úsalo para
  datos erróneos, NO para bajas reales. **Por qué:** eliminar no deja el registro de baja, así
  que no sirve para cumplimiento.

---

## 7. Etiquetas (tags)

Las etiquetas agrupan contactos para mandarles campañas por separado (por ejemplo: "Mazatlán",
"Interesados mayo").

1. En **Contactos**, clic en el ícono 🏷️ de un contacto.
2. En el panel que abre puedes elegir etiquetas ya creadas o **crear una nueva** (escribe el
   nombre y Enter).
3. Clic en **Guardar**.
4. Para ver solo los de una etiqueta, usa el selector "Todos los tags" en la búsqueda.

**Etiquetar varios a la vez:**
1. Marca la casilla de cada contacto (o la del encabezado para todos los de la página).
2. Arriba aparece una barra. Elige la etiqueta y clic en **Asignar tag**.
3. La etiqueta se **agrega** sin quitar las que ya tenían.

Para **quitar** una etiqueta de varios: selecciónalos, elige la etiqueta y clic en **Quitar tag**.

> Las etiquetas son solo para clasificar. No afectan bajas ni pausas.

---

### La pantalla Etiquetas

En el menú hay una pantalla **Etiquetas** con el catálogo completo. Ahí ves de un vistazo:

| Columna | Qué te dice |
|---|---|
| Etiqueta | El nombre. |
| Identificador | El nombre interno. **No cambia aunque renombres**, y es con el que el Excel de importación reconoce la etiqueta. |
| Contactos | Cuántos la tienen ahora. **Es un botón**: te lleva a Contactos ya filtrado por esa etiqueta. |
| Campañas | Cuántas campañas la tienen como destinatarios. |
| Creada | Cuándo se creó. |

Arriba tienes el resumen (cuántas etiquetas hay, cuántos contactos etiquetados y cuántas
etiquetas **sin usar**, útil para limpiar), un buscador y el botón **Nueva etiqueta**.

**Renombrar** (icono de lápiz) cambia solo el nombre que ves. El identificador se queda igual a
propósito: si cambiara, tus Excel viejos dejarían de reconocer la etiqueta y crearían una
duplicada.

### Borrar una etiqueta

Desde la pantalla **Etiquetas**, o desde el recuadro **Asignar tags** de cualquier contacto.
Antes de borrar, el sistema te dice **exactamente qué se lleva por delante**:

- Cuántos contactos dejarán de tenerla. **Los contactos NO se eliminan**, solo pierden la etiqueta.
- Cuántas campañas ya enviadas dejarán de mostrarla en sus destinatarios. Lo que ya se envió no
  cambia: sus mensajes y resultados siguen igual.

> 🛑 **Si hay una campaña sin enviar que tiene esa etiqueta como destinatarios, el sistema NO te
> deja borrarla**, y te dice cuál es. Es a propósito: esa campaña se quedaría sin lista de a
> quién enviar y le saldría a **todos** los contactos.
>
> **Qué hacer:** una campaña ya creada no se puede editar, así que tienes dos caminos: **borrar
> esa campaña** en la pantalla Campañas y volver a crearla con otros destinatarios, o **dejar la
> etiqueta como está** hasta que la campaña ya se haya enviado.

Borrar una etiqueta no se puede deshacer: hay que volver a crearla y reasignarla.

## 8. Campañas

Una campaña es un **envío masivo** a un grupo de contactos.

### Crear una campaña

1. Ve a **Campañas** → clic en **Nueva campaña**.
2. Llena el formulario:
   - **Nombre**: para reconocerla (ejemplo: "Promo mayo semana 1").
   - **Canal**: WhatsApp o SMS. Los dos usan **plantilla** - el mensaje NO se escribe a mano.
     - **WhatsApp**: eliges una plantilla aprobada y llenas sus datos.
     - **SMS**: eliges una plantilla de SMS. Ves la vista previa con cuántos caracteres tiene.
   - **Destinatarios**: a quién le llega. Es **obligatorio** elegir - no viene marcado para que
     no mandes a todos por error. Puedes elegir "Todos los contactos activos" o una **etiqueta**.
3. Clic en **Crear campaña**. Queda en **borrador** hasta que la ejecutes.

> **Por qué solo plantilla y no texto libre:** en WhatsApp, Meta solo permite plantillas que
> ellos aprobaron. Y en SMS, la plantilla ya trae el "responde STOP para baja" que exige la ley.
> Escribir a mano se saltaría esas reglas y podría costar una multa o una suspensión.

### Saludar a cada contacto por su nombre

Si la plantilla tiene variables, al crear la campaña te aparecen para llenarlas. **Lo que
escribas ahí le llega igual a todos.** Si pones "Joseph", los mil contactos reciben "Hola
Joseph".

Para que cada quien reciba **su** nombre, usa el botón 👤 que está junto a la variable: pone el
texto `{nombre}`, y el sistema lo cambia por el nombre de cada contacto en el momento de enviar.

Cómo lo resuelve el sistema:

| Nombre en la base | Lo que recibe |
|---|---|
| `JUAN PEREZ GARCIA` | Juan |
| `josé luis` | José |
| vacío, un número o basura | cliente |

Usa **solo el primer nombre** y lo escribe con mayúscula inicial, porque los archivos de Excel
suelen venir en mayúsculas o con el nombre completo, y "Hola JUAN PEREZ GARCIA" se lee mal.

> ⚠️ **Nunca queda vacío**: si el contacto no tiene nombre, se manda "cliente". Es a propósito -
> WhatsApp rechaza el mensaje si una variable va en blanco, y perderías toda la campaña.

> 💡 Redacta la plantilla pensando en ese caso: "Hola {{1}}, le saludamos de Prestamaz" funciona
> igual de bien con "Juan" que con "cliente".

> 💡 **SMS y horario**: a diferencia de WhatsApp, el SMS lo mandas cuando quieras. Si lo haces
> entre 11 de la noche y 7 de la mañana, el sistema te avisa (no te bloquea): enviar de
> madrugada suele generar más bajas. Tú decides.

> 💡 Al abrir un borrador ves su **alcance estimado**: cuántos contactos recibirían la campaña.

### Ejecutar una campaña

1. En **Campañas**, abre la que quieras enviar.
2. Clic en **Ejecutar**.
3. El sistema revisa que todo esté bien:
   - En **WhatsApp**: que la plantilla siga aprobada, que haya número activo y que sea horario
     válido (de lunes a viernes, de 9 de la mañana a 10 de la noche, hora del Centro de México
     - la misma de la Ciudad de México).
   - En **SMS**: no revisa horario (tú decides cuándo).
4. Si algo falla, te dice el motivo y **no manda nada**.
5. Si todo está bien, los mensajes se empiezan a enviar en segundo plano (pueden tardar unos
   minutos en salir todos).

> ⚠️ NO ejecutes la misma campaña dos veces. Si quieres reenviar, crea una campaña nueva. **Por
> qué:** ejecutarla otra vez volvería a mandar a los mismos y los saturaría.

### Ver el detalle de una campaña

Al abrir una campaña ves los totales (**Enviados**, **Fallidos**, **Descartados**, **Pendientes**)
y una tabla contacto por contacto con su **Estado** y el **Motivo / Error**:
- **Fallido:** salió, pero no llegó. La columna Motivo dice **por qué** en español (ej.
  *"El destinatario alcanzó su límite de mensajes de marketing"*). Pasa el cursor encima para
  ver el detalle completo. **Un fallido no se cobra:** WhatsApp solo cobra lo entregado.
- **Descartado:** el sistema no se lo mandó a propósito (ej. ya lo recibió hoy, está en
  enfriamiento, o es una baja). El motivo lo dice ahí. Tampoco se cobra.
- **Filtro de estados:** arriba de la tabla puedes ver **solo los fallidos**, solo los
  descartados, etc. Sirve para revisar de un jalón qué pasó con una campaña grande sin ir
  página por página.
- **Excluidos por baja:** si en el segmento hay contactos de **baja**, arriba aparece un aviso
  gris - *"N contactos del segmento no reciben por estar de baja"*. **Es solo informativo**: a
  esos ni se les intenta enviar (es lo correcto), pero así sabes por qué el total no cuadra.

---

## 9. Conversaciones y agentes

Cuando un contacto responde a un WhatsApp, su mensaje aparece en **Conversaciones**.

> ⚡ **En vivo:** si estás en esta pantalla, todo se actualiza solo sin recargar: respuestas
> nuevas, quién atiende, el estado y las palomitas de entregado/leído.

### Cómo responder

1. Ve a **Conversaciones** en el menú.
2. A la izquierda ves la lista de contactos con mensajes. Cada uno tiene un **estado**:
   - 🟢 **Abierta** - puedes responder texto libre.
   - ⚪ **Cerrada** - el contacto no responde desde hace más de 24 horas. Solo una campaña con
     plantilla lo reactiva.
   - 🟠 **Pospuesto** - pidió "No por ahora". No entra a campañas de WhatsApp, pero **sí le puedes
     escribir a mano** desde aquí.
   - 🔴 **Baja** - dado de baja, no se puede contactar.
3. Haz clic en un contacto para ver su historial.
4. Si está **Abierta**, escribe abajo y presiona Enter (o el botón enviar).
5. Si está **Cerrada**, el campo aparece deshabilitado.

**Respuestas rápidas**: los botones de abajo son mensajes frecuentes. Clic en uno y se carga el
texto; luego envías. *(El administrador puede crear y borrar estas respuestas rápidas desde el
panel derecho.)*

### Quién atiende cada conversación (multi-agente)

Varias personas pueden atender conversaciones a la vez. Para que no se pisen, cada conversación
se **asigna** a un agente.

**Asignación automática:** cuando un contacto escribe **por primera vez**, el sistema le asigna
un agente solo. El modo lo elige el administrador en **Configuración → Multi-agente**:

| Modo | Qué hace |
|---|---|
| **Menos chats activos** (predeterminado) | Se la da al agente que tenga menos conversaciones en ese momento. Reparte parejo la carga. |
| **Primer disponible** | Se la da al primer agente activo. Más simple, menos parejo. |

- Si **no hay agentes activos**, la conversación queda **"Sin asignar"** y aparece un chip
  naranja. El admin u operador la puede repartir a mano.
- La asignación automática pasa **una sola vez** (en el primer mensaje). Después no se reasigna
  sola - se hace a mano si hace falta.
- **Excepción - la baja NO asigna agente:** si la primera (o siguiente) respuesta del contacto es
  una **baja** (STOP o DAR DE BAJA), el sistema **no le asigna** agente, y si ya tenía uno lo
  **suelta** (queda "Sin asignar"). Tiene sentido: a un contacto de baja **ya no se le puede
  escribir**, así que no ocupa a nadie.

**Cómo se lee la lista** (son dos cosas separadas: el estado y quién atiende):
- Chip **"Sin asignar"** (naranja) - nadie la tiene todavía.
- Chip **"Tú"** (verde) + barra verde a la izquierda - es tuya.
- **Iniciales** de un agente - la atiende otra persona (pasa el cursor para ver el nombre).

**Botones de asignación:**
- **Tomar conversación** - cualquier agente se la asigna a sí mismo.
- **Asignar a agente** (solo admin y operador) - la pasa a otro miembro del equipo, con el
  selector de agentes. Úsalo si un agente ya no está disponible.

> Los agentes solo ven **sus** conversaciones. Admin y operador ven todas.

---

### Cambios de turno: reasignar y ver el historial

En el panel derecho de una conversación, la sección **Asignación** tiene tres acciones (solo
para operador y administrador):

- **Reasignar**: elige a otro agente y pásasela. Sirve para el cambio de turno.
- **Dejar sin asignar**: la suelta para que la tome quien entre. No se pierde nada.
- **Ver historial**: abre el detalle de todos los movimientos de esa conversación.

El historial muestra, del movimiento más reciente al más viejo:

| | |
|---|---|
| **Qué movimiento** | Asignación automática · Asignada · Tomada por el agente · Reasignada · Sin asignar |
| **A quién** | El agente que quedó a cargo. |
| **Cuándo** | Fecha y hora. |
| **Quién lo hizo** | El usuario que movió la conversación, o *el sistema* cuando fue el reparto automático. |

> 📌 **El historial nunca se borra.** Aunque la conversación se suelte o cambie de agente diez
> veces, quedan los diez movimientos. Es lo que permite revisar el seguimiento que se le dio a
> un cliente.

Si intentas reasignar al mismo agente que ya la tiene, el sistema lo rechaza: así el historial
no se llena de movimientos que no movieron nada.

### Reporte de agentes

En el menú hay una pantalla **Reporte de agentes** (operador y administrador; el agente no la
ve, no debe ver la carga de sus compañeros). Muestra cuántas conversaciones lleva cada quien.

Trae **dos números** porque "cuántas conversaciones tiene" se puede entender de dos formas:

| Columna | Qué es |
|---|---|
| **Recibidas en el periodo** | Las que se le asignaron entre las fechas del filtro. Responde al filtro de fecha. |
| **Abiertas ahora** | Las que tiene a su cargo en este momento. **No cambia con el filtro de fecha.** |

> Un agente puede haber recibido 12 hoy y tener 40 abiertas: arrastra conversaciones de días
> anteriores que nadie ha cerrado ni reasignado. Por eso van las dos columnas.

Filtros: **Desde**, **Hasta** y **Agente**. El botón **Hoy** vuelve al día de hoy. Al entrar,
la pantalla ya muestra el día de hoy sin que tengas que elegir nada.

Los botones **Descargar Excel** y **Descargar PDF** bajan exactamente la tabla que estás
viendo, con los filtros aplicados. El nombre del archivo lleva el periodo.

Quien aparece: todos los que pueden atender conversaciones (agentes, operadores y
administradores), **aunque estén en cero**. Un agente en cero también es información.

## 10. Respuestas SMS

Los SMS que te responden aparecen en **Respuestas SMS** (en el menú).

A diferencia de Conversaciones (WhatsApp), esto es **solo de lectura**: ves las respuestas pero
no se contesta desde aquí.

Las respuestas vienen **agrupadas por contacto**: cada fila es una persona. Si respondió varias
veces, haz clic en la fila para ver todos sus mensajes.

Cada fila muestra:
- **Fecha** de su última respuesta.
- **Contacto** (nombre y celular).
- **Último mensaje**.
- **Msgs** - cuántas veces respondió.
- **Acción** - una etiqueta:
  - **"Interesado"** (verde) si respondió **SÍ** o **INFO** - es tu prospecto, dale seguimiento.
  - **"Baja automática"** (roja) si respondió **STOP** o **DAR DE BAJA** - ya lo dimos de baja de SMS.

**Herramientas:** buscar por número/nombre/texto, filtrar (Todas / Interesados / Bajas), y el
botón ↻ para traer las más recientes.

---

## 11. Bajas: contactos que piden no recibir más

Cuando un contacto responde con una de estas palabras exactas:

**STOP · DAR DE BAJA**

El sistema lo da de **baja** automáticamente y nunca más le envía por ese medio.

El mensaje tiene que ser **solo** esa frase. No importan mayúsculas, acentos, espacios de
más ni el punto final: "Dar de baja." cuenta igual que "DAR DE BAJA". Pero una frase que
la contiene, como *"no quiero dar de baja mi crédito"*, **no** da de baja.

> **Ojo, esto cambió (agosto 2026).** Antes también daban de baja las palabras `NO`, `BAJA`
> y `CANCELAR`. Se retiraron porque causaban bajas falsas: un contacto que ya había dicho
> "Me interesa" contestó **"No"** a la pregunta de un agente y el sistema lo bloqueó para
> siempre. Si te toca ver un contacto que quedó de baja por error de esos, avisa a soporte:
> lo puede reactivar.

### Importante: la baja NO funciona igual en los dos canales

Depende de **por dónde** pidió la baja:

| El contacto dijo STOP / DAR DE BAJA por... | ¿Qué se bloquea? |
|---|---|
| **WhatsApp** (o le diste "Dar de baja" a mano) | **Los dos canales**: ya no recibe WhatsApp NI SMS. |
| **SMS** | **Solo SMS**: deja de recibir SMS, pero **sigue recibiendo WhatsApp**. |

**Por qué esta diferencia:** una baja por WhatsApp se trata como "no me contactes por ningún
medio" (es el canal principal y el más delicado con Meta). Una baja por SMS se entiende como
"no me mandes textos", y no necesariamente que rechace el WhatsApp. Un contacto puede entonces
tener **Baja SMS** y seguir **Activo** para WhatsApp.

Otras notas:
- Las bajas se pueden **ver** en Contactos (filtro "Baja" para WhatsApp, "Solo bajas SMS" para SMS)
  pero no se borran (queda registro).
- El botón **Dar de baja** del panel es una baja manual y bloquea **los dos canales** (igual que
  una baja por WhatsApp).

> **Por qué es irreversible:** la ley exige respetar la baja. Aunque el contacto luego diga que
> sí quiere, no se puede "revivir" una baja - es la prueba de que respetamos su decisión. Si de
> verdad quiere volver, tendría que ser un caso que revisa soporte.

---

## 12. Control de envíos: las reglas que te protegen

El sistema aplica varias reglas automáticas para no saturar contactos ni dañar la cuenta. No
las puedes apagar desde el uso normal; algunas las ajusta soporte. Aquí están todas, con el por
qué:

| Regla | Qué hace | Por qué | ¿Se puede cambiar? |
|---|---|---|---|
| **Horario WhatsApp** | Solo envía de Lunes a Viernes, 9am-10pm, hora del Centro (CDMX) | Fuera de ese horario Meta lo puede tomar como spam | No. Es fijo. |
| **Límite diario WhatsApp** | Cada número tiene un tope de mensajes al día | La cuenta se "madura" de a poco; pasarse la daña | No. El sistema lo sube solo con el tiempo. |
| **No repetir el mismo día** | Un contacto no recibe dos veces el mismo día por el mismo canal | Evita saturarlo | No. |
| **Enfriamiento** | Tras recibir, el contacto espera un tiempo antes de recibir otra vez (por el mismo canal) | Que no le lleguen campañas muy seguidas | Sí, soporte. Por defecto **30 días**, mínimo **7**. |
| **Pospuesto** | Si el contacto pide "No por ahora", se pausa (solo WhatsApp) hasta que pase | Respeta que ahorita no quiere | Vuelve solo. Dura lo mismo que el enfriamiento. |
| **Inalcanzable (WhatsApp)** | Si 3 mensajes seguidos no le llegan (sin ninguna entrega en medio), se saca de campañas. El tope de 3 es fijo, no depende del enfriamiento | Probablemente bloqueó; seguir enviándole baja la calidad y arriesga la cuenta | El admin lo puede reactivar. |
| **Autobaja por rebotes (SMS)** | Tras varios SMS que rebotan seguidos, se bloquea el SMS de ese contacto | Dejar de gastar en números que no reciben | Sí, soporte. **Apagada por defecto** (no bloquea salvo que se active). |

**Cosas clave que conviene tener claras:**

- **Cada canal cuenta por separado.** WhatsApp lleva su cuenta y SMS la suya. Un contacto que
  recibió WhatsApp hoy **sí** puede recibir un SMS hoy. Lo único que bloquea los dos a la vez es
  una **baja por WhatsApp** (o la baja manual). Una **baja por SMS** solo frena el SMS (ver
  [Bajas](#11-bajas-contactos-que-piden-no-recibir-mas)).
- **El "Pospuesto" es solo de WhatsApp.** Por SMS sigue disponible, y en Conversaciones le puedes
  escribir a mano aunque esté pospuesto.
- **Lo que ajusta soporte** (enfriamiento, autobaja) se cambia en **Configuración**. Si necesitas
  cambiar un tiempo, pídeselo a soporte con el motivo.

> Cuando veas un contacto **Activo** pero que "no se le envió", casi siempre es una de estas
> reglas (enfriamiento, pospuesto, enviado hoy). No es un error: es el sistema cuidándolo.

---

## 13. Reportes en Excel

| Reporte | Cómo bajarlo | Qué trae |
|---|---|---|
| **Contactos** | Contactos → botón **Exportar Excel** | Teléfono, nombre, estado, de dónde salió el contacto, hasta cuándo está pospuesto y cuándo se dio de alta. |
| **Mensajes** | Panel → botón ↓ en "Últimos mensajes" | Los últimos 10,000 envíos: canal (WhatsApp o SMS), número que envió, destino, plantilla, estado de entrega, **motivo del fallo** y fecha. |

Los dos archivos vienen en español y con la hora del Centro (CDMX), la misma que ves en el
panel. Los puedes abrir en Excel o en Google Sheets tal cual.

---

## 14. ¿Qué hago si...? (cómo reaccionar)

Guía rápida de reacción. Si algo se ve raro, busca aquí antes de asustarte.

| Situación | Qué significa | Qué hacer |
|---|---|---|
| **El semáforo del número está en ROJO** | Meta bajó la calidad del número | **No ejecutes campañas.** Avisa a soporte. El sistema ya pausó solo. |
| **El número dice PAUSADO** | El sistema detuvo los envíos para proteger la cuenta | No reanudes. Avisa a soporte. |
| **No me deja ejecutar una campaña de WhatsApp** | Estás fuera del horario permitido (Lunes a Viernes, 9am-10pm hora del Centro/CDMX) | Espera al horario. Es normal, no es un error. |
| **Muchas alertas de "entrega fallida" seguidas** | Los mensajes no están llegando | Revisa la Salud del número en el Panel. Si sigue, avisa a soporte. |
| **Alerta de "problema con los SMS"** | El sistema no recibe confirmaciones de SMS | Avisa a soporte (es tema del teléfono que manda los SMS). |
| **Un contacto dice que no le llegó el mensaje** | Puede no tener WhatsApp, o estar Pospuesto / en Enfriamiento | Búscalo en Contactos y mira su estado de entregabilidad. |
| **Quiero mandar una plantilla nueva y no aparece** | Solo salen plantillas aprobadas por Meta | El administrador debe crearla y esperar aprobación. Ver **Guía Meta**. |
| **Me dice que la plantilla "lleva imagen y no se ha subido"** | La plantilla usa imagen de encabezado y falta cargarla en el panel | El administrador la sube en Plantillas. Sin ella los mensajes saldrían fallidos, por eso el sistema frena la campaña. |
| **Un agente ya no trabaja aquí** | Sus conversaciones quedan con él asignadas | Admin u operador las reasigna a otro agente con "Asignar a agente". |
| **Sale una barra roja: "Las tareas automáticas llevan X detenidas"** | El reloj interno del sistema se detuvo | **Avisa a soporte cuanto antes.** Puedes seguir trabajando: las campañas siguen enviándose. Lo que se detuvo es lo de atrás. |

### La barra roja de "tareas automáticas detenidas"

Hay cosas que el sistema hace solo, de madrugada y sin que nadie las pida: dar de alta los
clientes nuevos, subir poco a poco los límites de envío, limpiar números muertos y ponerse al
día con los SMS. Un reloj interno las dispara.

Si ese reloj se detiene, sale una barra roja arriba de todas las pantallas.

**Lo que sigue funcionando:** las campañas se envían igual, las respuestas llegan igual, y
puedes trabajar normal. No pares.

**Lo que se detiene:** dejan de entrar clientes nuevos y deja de actualizarse el estado de los
SMS. No se rompe nada, pero el sistema se va quedando atrás mientras siga la barra.

**Qué hacer:** avisar a soporte y seguir trabajando. Es un arreglo del servidor, no algo que se
resuelva desde el panel. La barra desaparece sola en cuanto se corrige.

> Todo lo que diga "avisa a soporte" o "ver Guía Meta" son cosas que resuelve quien administra
> la cuenta de Facebook/Meta. Tú no tienes que tocar nada de eso.

---

## 15. Preguntas frecuentes

**¿Por qué no puedo enviar una campaña de WhatsApp ahorita?**
WhatsApp solo permite enviar de lunes a viernes, de 9 de la mañana a 10 de la noche, hora del
Centro de México (la de la CDMX). El sistema lo bloquea fuera de ese horario para cuidar la
cuenta. El SMS no tiene ese límite. *Nota: se usa la hora del Centro aunque estés en otra zona
(por ejemplo Sinaloa o Baja California); así el mensaje nunca llega demasiado temprano ni tarde
en ninguna parte del país.*

**¿Por qué el número aparece "PAUSADO"?**
Meta detectó algo inusual y el sistema pausó los envíos solo para proteger la cuenta. El
administrador debe revisar antes de reanudar.

**¿Se puede enviar a un número que no tiene WhatsApp?**
No. Se marca solo como "inválido" tras el primer intento y no se vuelve a intentar.

**¿Cuántos mensajes se pueden enviar por día?**
Depende de qué tan "madura" esté la cuenta. Empieza bajito y sube solo con el tiempo si la
calidad es buena. El sistema controla esto - nadie del equipo lo puede cambiar.

**¿Qué es "Pospuesto"?**
Si un contacto toca "No por ahora" en un mensaje, el sistema lo pospone un tiempo (por defecto
30 días) para no molestarlo. Vuelve solo. Esta pausa solo aplica a WhatsApp; por SMS sigue
disponible. En Conversaciones le puedes seguir escribiendo a mano.

**¿Un contacto puede recibir WhatsApp y SMS el mismo día?**
Sí. Cada canal cuenta por separado. Lo único que bloquea los dos a la vez es una **baja por
WhatsApp** (o la baja manual). Si pidió baja **por SMS**, deja de recibir SMS pero sigue
recibiendo WhatsApp.

**¿Puedo cambiar el enfriamiento o el tiempo de la pausa "Pospuesto"?**
Desde el uso normal no. Lo ajusta soporte en Configuración (mínimo 7 días). Pídeselo con el
motivo si de verdad lo necesitas.

**Olvidé mi contraseña, ¿cómo la recupero?**
El login no tiene recuperación automática. Pídele al administrador que te ponga una nueva desde
la pantalla Usuarios, con el botón **Contraseña** (🔑) que está junto a tu nombre.

---

> **¿Eres el administrador?** Para crear plantillas sin arriesgar multas, generar permisos de
> Meta, agregar números o entender las alertas de Facebook, ve a la **Guía Meta**.
