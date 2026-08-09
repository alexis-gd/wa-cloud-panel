# Guía de Facebook y Meta - Panel Prestamaz

> Guía técnica, solo para **administrador** y **soporte**. El operador y el agente
> no necesitan nada de esto para su trabajo diario.
>
> Aquí está todo lo que se configura del lado de Meta (Facebook): crear plantillas
> sin que las rechacen ni generen multas, generar el permiso (token) que deja enviar,
> agregar números, verificar el negocio e interpretar las alertas de Business Manager.
>
> Para el uso diario del panel (campañas, contactos, respuestas) hay una guía aparte:
> la **Guía de uso**.

---

## Cómo leer esta guía

- Esta guía es para quien administra la cuenta de Meta del negocio. Si tu trabajo es
  mandar campañas o atender respuestas, no necesitas esto: usa la **Guía de uso**.
- **Soporte** o **administrador del sistema** = la persona que instaló y mantiene el
  sistema por dentro. Cuando la guía dice "pídeselo a soporte", es a esa persona. Varias
  cosas de aquí (agregar palabras clave, cambiar el token en el servidor) solo las hace
  soporte.
- Los pasos que se hacen en la página de Meta se marcan con el sitio: **business.facebook.com**
  (Business Manager) o **developers.facebook.com** (panel de desarrollador).
- Regla de oro: **si tienes duda, no toques nada de Meta. Pregunta primero.** Un error
  aquí puede costar una multa o el bloqueo de la cuenta de WhatsApp.

---

## Índice

1. [¿Qué es Meta y por qué hay que cuidarla?](#1-que-es-meta-y-por-que-hay-que-cuidarla)
2. [Antes de tocar nada: 4 reglas que evitan multas y bloqueos](#2-antes-de-tocar-nada-4-reglas-que-evitan-multas-y-bloqueos)
3. [Crear plantillas de WhatsApp sin que las rechacen](#3-crear-plantillas-de-whatsapp-sin-que-las-rechacen)
4. [Palabras clave que el sistema entiende (lo más importante)](#4-palabras-clave-que-el-sistema-entiende-lo-mas-importante)
5. [Crear mensajes de SMS](#5-crear-mensajes-de-sms)
6. [Agregar teléfonos para enviar SMS](#6-agregar-telefonos-para-enviar-sms)
7. [El permiso para enviar: el token permanente](#7-el-permiso-para-enviar-el-token-permanente)
8. [Agregar un número de WhatsApp](#8-agregar-un-numero-de-whatsapp)
9. [Agregar un número de prueba (sandbox)](#9-agregar-un-numero-de-prueba-sandbox)
10. [Verificar el negocio en Meta (Business Verification)](#10-verificar-el-negocio-en-meta-business-verification)
11. [Interpretar las alertas de Business Manager](#11-interpretar-las-alertas-de-business-manager)
12. [Códigos de error y qué hacer](#12-codigos-de-error-y-que-hacer)
13. [Preguntas frecuentes](#13-preguntas-frecuentes)

---

## 1. ¿Qué es Meta y por qué hay que cuidarla?

**Meta** es la empresa dueña de Facebook y WhatsApp. Para mandar mensajes masivos por
WhatsApp de forma legal, el negocio usa la **WhatsApp Cloud API** de Meta: es el canal
oficial, no una app pirata ni un WhatsApp normal.

Todo vive dentro de una cuenta llamada **Business Manager** (o WABA - WhatsApp Business
Account). Ahí están los números, las plantillas y los permisos.

**Lo que tienes que entender antes que nada:**

- La cuenta de WhatsApp es el activo más valioso del sistema. Si Meta la bloquea, **no
  hay envíos por WhatsApp hasta resolverlo** (y a veces no se resuelve). El SMS es un
  canal aparte y no se ve afectado.
- Meta bloquea cuentas por spam, por mensajes sin permiso del destinatario, por ignorar
  a quien pide baja, o por crecer el volumen demasiado rápido. El panel está hecho para
  que esos errores **no se puedan cometer** desde el uso diario. Pero del lado de Meta
  (esta guía) sí se pueden cometer a mano, por eso hay que tener cuidado.
- Hay dos cuentas distintas según la etapa:

| Etapa | Cuenta | Número |
|---|---|---|
| Pruebas / desarrollo | Cuenta sandbox del que instaló el sistema | Número de prueba de Meta |
| Producción (real) | Cuenta de Meta del negocio (Prestamaz) | SIM dedicada verificada |

---

## 2. Antes de tocar nada: 4 reglas que evitan multas y bloqueos

Estas cuatro reglas cubren el 90% de los problemas. Si las respetas, es muy difícil que
algo salga mal.

1. **Solo se envía a quien dio permiso.** Meta y la ley mexicana (LFPDPPP) exigen que el
   contacto haya autorizado recibir publicidad. Nunca cargar bases compradas o "frías"
   sin consentimiento.
2. **Toda plantilla de marketing DEBE incluir en su TEXTO cómo darse de baja.** Es obligatorio
   por ley (LFPDPPP) y por la política de Meta. Pon en el **cuerpo del mensaje** una línea como
   **"Responde STOP para dejar de recibir mensajes"**. El sistema da de baja cuando el contacto
   **escribe** una de estas palabras: `STOP`, `BAJA`, `CANCELAR` o `NO` (ver
   [sección 4](#4-palabras-clave-que-el-sistema-entiende-lo-mas-importante)). También cuenta el
   opt-out **nativo** de WhatsApp (el usuario detiene el marketing desde la app; a nosotros nos
   llega solo), pero eso **NO te exime** de poner la instrucción de texto. **No dependas de un
   botón para la baja:** los botones de la plantilla solo sirven para "Me interesa" y "No por
   ahora" (sección 3); un botón "No, gracias" o "Baja" **NO da de baja** (el sistema no lo procesa
   como tal). **No confundir la baja con el botón "no por ahora": son cosas distintas** (ver el
   recuadro de abajo).
3. **La publicidad financiera lleva el CAT.** Por ley (CONDUSEF) toda promoción de
   préstamos debe mostrar el CAT promedio informativo. Sin él, Meta puede rechazar la
   plantilla. Formato exacto: `CAT promedio informativo 485.5% sin IVA`.
4. **No se apura el crecimiento.** Un número nuevo empieza con pocos mensajes al día y
   sube solo (warm-up). Nunca intentar forzar más volumen del que el sistema permite:
   es la vía más rápida a un bloqueo.

> ⚠️ **SÚPER IMPORTANTE - la línea de baja es TU responsabilidad.**
> El panel y Meta **aprueban la plantilla aunque NO pongas** la línea "Responde STOP...". El
> sistema **no te obliga** a escribirla. Por eso lo dejamos advertido aquí, negro sobre blanco:
> **si la omites, es tu decisión y tu riesgo, no un fallo ni un olvido del sistema.**
> - **Riesgo de omitirla:** multa por la ley de datos (**LFPDPPP**) y **bloqueo de la cuenta de
>   WhatsApp por Meta** (ignorar/impedir la baja es de las causas más comunes de suspensión).
> - **Regla simple:** toda plantilla de marketing lleva SIEMPRE, en el cuerpo, una línea de baja
>   como *"Responde STOP para dejar de recibir mensajes"*. Sin excepción.

> **Baja y "no por ahora" NO son lo mismo:**
> - **Baja** = el contacto ya no quiere recibir NADA, nunca más. Es permanente y bloquea
>   los dos canales (WhatsApp y SMS). Palabras: `BAJA`, `STOP`, `CANCELAR`, `NO`.
> - **Pospuesto ("no por ahora")** = el contacto no dice que no para siempre, solo "ahorita
>   no". Pausa las campañas de WhatsApp un tiempo y luego se le puede volver a escribir.
>   Se activa con un botón cuyo título contenga "no por ahora".
>
> Son dos botones y dos efectos completamente diferentes. No los mezcles en la misma
> plantilla ni uses el texto de uno para el otro.

> Si alguna vez tienes que elegir entre "mandar más rápido" y "cuidar la cuenta", **siempre
> gana cuidar la cuenta**. Un número bloqueado cuesta semanas de recuperación.

---

## 3. Crear plantillas de WhatsApp sin que las rechacen

Una **plantilla** es el mensaje pre-aprobado que WhatsApp permite mandar en frío (a quien
no te ha escrito). Meta las revisa una por una. El panel **solo deja usar las que Meta ya
aprobó** - nunca se escribe el nombre de una plantilla a mano.

### Dónde se crean

En **business.facebook.com** -> **Administrador de WhatsApp** -> **Plantillas de mensajes**
-> **Crear plantilla**. (No se crean desde el panel; el panel solo las sincroniza y las
muestra ya aprobadas.)

### Reglas para que la aprueben (y no penalicen la cuenta)

| Campo | Valor correcto | Por qué |
|---|---|---|
| **Categoría** | **Marketing** | Es publicidad. Nunca "Utility" (eso es para avisos transaccionales) ni "Authentication". |
| **Idioma** | **es_MX** (Español - México) | El público es mexicano. |
| **CAT** | Incluir `CAT promedio informativo 485.5% sin IVA` en el texto | Obligatorio por ley para publicidad de préstamos. Sin él, riesgo de rechazo. |
| **Opción de baja** | Que el contacto pueda responder una palabra de baja (ver sección 4) | Obligatorio. Además es lo que hace que el sistema procese la baja de verdad. |
| **Contenido** | Claro, sin promesas exageradas ni datos falsos | Meta rechaza contenido engañoso o "spam". |

### Los botones de la plantilla (posponer, interés)

Cuando el contacto toca un botón, **WhatsApp envía como respuesta el título del botón**, y el
sistema decide qué hacer. **Solo hay dos botones que el sistema entiende:**

- **Botón de posponer ("no por ahora"):** su título debe contener **"no por ahora"**.
  Activa el Pospuesto (pausa temporal de WhatsApp), NO la baja.
- **Botón de interés:** su título debe contener **"me interesa"**. Marca al contacto como
  interesado y lo pasa a un agente.

> **La baja NO se hace con un botón.** No existe un "botón de baja" que funcione: si pones un
> botón "No, gracias" o "Baja", el sistema **no lo procesa como baja** y el contacto se queda
> sin darse de baja (riesgo legal). La baja funciona por dos vías: (1) el contacto **escribe**
> `STOP`/`BAJA`/`CANCELAR`/`NO`, y (2) el opt-out **nativo** de WhatsApp. Por eso la línea
> **"Responde STOP para dejar de recibir mensajes" va SIEMPRE en el texto** (ver regla 2 de la
> sección 2). No mezcles esto con "no por ahora": son cosas distintas.

### Por qué se rechaza una plantilla (y qué NO hacer)

Las causas más comunes de rechazo:

- Contenido que suena a spam o promete cosas imposibles ("préstamo garantizado sin
  requisitos").
- Falta el botón o texto de baja.
- Contenido engañoso.
- **Reenviar la misma plantilla rechazada sin cambiarle nada.** Esto sí es una señal de
  spam para Meta y puede bajar la reputación.

> Someter una plantilla a revisión NO baja la reputación de la cuenta. Lo que la baja es
> reenviar el mismo contenido rechazado una y otra vez. Si te la rechazan: **cambia el
> texto** o espera 24h, no la reenvíes igual.

### Después de crearla

1. Meta la revisa (de minutos a 24h). Queda en estado "En revisión", luego "Aprobada" o
   "Rechazada".
2. En el panel, ve a **Plantillas** y usa **Sincronizar**: baja de Meta las plantillas y
   sus estados.
3. Solo las **Aprobadas** aparecerán para elegir al crear una campaña. Las demás no se
   pueden usar (a propósito).

---

## 4. Palabras clave que el sistema entiende (lo más importante)

**Esta es la sección más crítica de toda la guía.** El sistema reacciona a las respuestas
de los contactos **solo si contienen ciertas palabras exactas**. Distinción clave: **la baja
solo cuenta si el contacto ESCRIBE la palabra** (o usa el opt-out nativo de WhatsApp) -
**nunca por un botón**. Los botones solo sirven para "Me interesa" y "No por ahora"; con otras
palabras (o uno que pretenda dar baja) **el sistema no reacciona**.

### Qué entiende el sistema en WhatsApp

| Acción del contacto | Palabras que el sistema entiende | Qué hace el sistema |
|---|---|---|
| **Pedir baja** | El contacto **escribe** (texto, no botón) exactamente `STOP`, `BAJA`, `CANCELAR` o `NO` | Marca al contacto como Baja. Nunca se le vuelve a enviar (WhatsApp y SMS). Es permanente. |
| **Posponer** (botón) | El título del botón contiene **"no por ahora"** | Activa el Pospuesto: pausa las campañas de WhatsApp a ese contacto un tiempo. NO es baja. |
| **Mostrar interés** (botón) | El título del botón contiene **"me interesa"** | Marca al contacto como interesado y lo pasa a un agente. |

> **Baja sin escribir nada:** el contacto también puede darse de baja **desde su propia app
> de WhatsApp** (tocando "dejar de recibir mensajes de esta empresa"), sin mandarnos ningún
> texto. En ese caso Meta nos avisa y el sistema lo marca como Baja igual. No hay que hacer
> nada - es automático.

### Qué entiende el sistema en SMS

| Acción del contacto | Palabras que el sistema entiende | Qué hace el sistema |
|---|---|---|
| **Pedir baja** | El mensaje es exactamente `STOP`, `BAJA`, `CANCELAR` o `NO` | Marca al contacto como Baja (bloquea ambos canales). |
| **Mostrar interés** | El mensaje es `SI`, `INFO` o `INFORMACION` | Marca interés y lo pasa a un agente. |

> El SMS no tiene botones (es texto plano), así que en SMS solo cuentan las palabras que el
> contacto escribe. No hay "posponer" por SMS: el Pospuesto es solo de WhatsApp.

### La regla de oro

> **Si creas un botón con una palabra distinta a las de estas tablas, el sistema no
> reacciona.** Por ejemplo: un botón que diga "Cuéntame más" no marca interés; uno que
> diga "Después" no pospone. Y **la baja no se hace con botón** (ver abajo).
>
> Por eso, al diseñar una plantilla:
> - Para la **baja**: **NO uses un botón** (no funciona). Deja SIEMPRE en el **texto** del
>   mensaje "Responde STOP para dejar de recibir mensajes". El contacto se da de baja
>   escribiéndolo, o desde el opt-out nativo de WhatsApp.
> - Para el botón de **posponer**, el título debe contener **"no por ahora"**.
> - Para el botón de **interés**, el título debe contener **"me interesa"**.
>
> **¿Necesitas que el sistema entienda una palabra nueva** (por ejemplo aceptar "QUITAR"
> como baja)? Eso no se cambia desde el panel: **pídeselo a soporte.** Es a propósito -
> las palabras clave no se cambian solas para no romper el cumplimiento legal.

---

## 5. Crear mensajes de SMS

El SMS es más simple que WhatsApp: **no necesita plantilla aprobada por nadie**. Es texto
plano y se escribe directo al crear la campaña en el panel. Pero tiene sus propias reglas
de cumplimiento.

### Reglas para el texto del SMS

- **Identifica al remitente.** Empieza con "Prestamaz te informa:" o similar. El contacto
  debe saber quién le escribe.
- **Incluye la opción de baja al final.** Texto recomendado: `Responde STOP para no recibir más`.
  Es obligatorio por ley. (Recuerda: el sistema da de baja con `STOP`, `BAJA`, `CANCELAR`
  o `NO` - ver sección 4.)
- **Cuida el largo.** 160 caracteres = 1 SMS. Más de eso se parte en 2 o 3 segmentos (y
  cuesta más). El panel te avisa cuántos segmentos son.
- **No pongas contenido prohibido:** nada político, religioso, engañoso ni ilegal.

### Horario del SMS

A diferencia de WhatsApp (horario forzado por ley y por Meta), el SMS **no tiene horario
obligatorio** en México. El sistema deja enviar a cualquier hora, pero avisa si programas
entre 11PM y 7AM (más bajas y más filtrado de las operadoras). Es aviso, no bloqueo.

### REPEP (importante)

Si un número está en el **REPEP** (Registro Público para Evitar Publicidad), hay 30 días
para dejar de enviarle. Es responsabilidad del negocio revisarlo periódicamente.

---

## 6. Agregar teléfonos para enviar SMS

Los SMS no salen de internet: salen de **teléfonos Android reales con chip** (SIM Telcel u
otra) conectados al sistema. Al principio hay 1 teléfono; para mandar más SMS al día se
suman más teléfonos. Cada teléfono reparte la carga con los demás **automáticamente** - el
panel no se toca, solo se da de alta el teléfono nuevo.

### Agregar un teléfono nuevo

**Qué necesitas por cada teléfono nuevo:**
- Un celular Android con su chip y **saldo** (o plan con SMS).
- La app **SMS Gateway for Android** instalada (la misma que ya usa el teléfono actual).
- El **token privado** del sistema y la dirección del servidor: los tiene **soporte**.

**Pasos (los hace soporte o quien administra los teléfonos):**
1. Instalar la app **SMS Gateway for Android** en el teléfono nuevo.
2. En la app: **Settings -> Cloud server**, y poner la **dirección del servidor** y el
   **token privado** que da soporte (los mismos que el teléfono que ya funciona).
3. Encender el interruptor a **online**. En unos segundos el teléfono queda conectado.
4. **Muy importante - que Android no mate la app** (si no, deja de enviar y de recibir):
   - **Inicio automático (Autostart): activado.**
   - **Batería: "Sin restricciones"** para esa app.
   - Dejar la app **fija/bloqueada** en la lista de apps recientes.
   - Teléfono **enchufado** y con **WiFi estable**.
5. Mandar un SMS de prueba desde el panel (Campañas -> prueba) para confirmar que llega.

> No hay que configurar nada en el panel: en cuanto el teléfono queda conectado, el sistema
> empieza a repartirle SMS solo. Puedes sumar los que quieras (típico: 5 a 8 teléfonos).

### ¿Un SMS no salió: es el chip o es la app?

Regla rápida: **si NINGÚN SMS se está entregando, es la app; si solo fallan algunos, es un chip.**

- **Es la app (se cerró):** ningún SMS pasa a "Entregado" y no entran respuestas. El panel lo
  avisa en **Configuración -> Salud del webhook SMS** y en la campana. Solución: abrir la app
  del teléfono, ponerla **online** y revisar el punto 4 de arriba (que Android no la mate).
- **Es un chip (uno de varios):** los demás teléfonos siguen enviando bien; solo fallan algunos
  SMS. Suele ser **saldo agotado, sin señal, o el chip bloqueado por la operadora**. Solución:
  revisar saldo y señal de ese teléfono; si la operadora lo bloqueó, cambiar el chip. El resto
  sigue trabajando mientras tanto.

---

## 7. El permiso para enviar: el token permanente

El **token** es la llave que le da al panel permiso de enviar por la cuenta de WhatsApp.
Sin token válido, no hay envíos por WhatsApp.

### Qué tipo de token se usa

Un **System User Token** (token de usuario del sistema). Su ventaja: **no expira**. Es el
que debe estar en producción. (Hay tokens temporales que duran ~24h; esos son solo para
pruebas rápidas, nunca para producción.)

### Cuándo hay que renovarlo

Normalmente **nunca**. Pero puede invalidarse si alguien revoca los permisos del usuario
del sistema en Business Manager, o si se cambia la contraseña maestra de la cuenta de Meta.

**Señal de alerta:** el panel muestra envíos fallidos con **código 190** (token expirado).

### Cómo generar uno nuevo

1. Entra a **business.facebook.com** -> **Configuración del negocio** (icono de engranaje).
2. Menú izquierdo: **Usuarios -> Usuarios del sistema**.
3. Selecciona el usuario del sistema (`waclouddev` o el que corresponda).
4. Clic en **Generar nuevo token**.
5. Elige la app (`wa-api-test`) y marca estos dos permisos:
   - `whatsapp_business_messaging`
   - `whatsapp_business_management`
6. Copia el token (aparece **una sola vez** - guárdalo de inmediato).

### Cómo ponerlo en el panel

- **Opción A - Panel web:** ve a **Configuración** -> campo **Pegar nuevo token** -> pega ->
  **Guardar token**.
- **Opción B - servidor (soporte):** `php artisan wa:update-token TOKEN_AQUI`

> Por seguridad el panel **nunca** muestra el token completo: solo confirma que quedó
> guardado (los últimos 4 caracteres). Es normal, no es un error.

---

## 8. Agregar un número de WhatsApp

**Cuándo se hace:** cuando el negocio quiere más capacidad de envío, o reemplazar un
número con calidad degradada.

### Requisitos (los prepara soporte / administrador)

- Una **SIM nueva dedicada** exclusivamente a WhatsApp (Telcel o AT&T recomendados).
  **Nunca usar el número oficial del negocio** para campañas.
- El número debe poder recibir una llamada o SMS para la verificación inicial de Meta.
- Se registra en **business.facebook.com** -> **Cuentas de WhatsApp -> Números de teléfono
  -> Agregar número**.

### Cómo darlo de alta en el panel

Después de registrarlo en Meta necesitas dos datos que Meta te da: el **Phone number ID** y
el **WABA ID** (se ven en business.facebook.com, en la configuración del número y de la cuenta).

1. En el panel, entra a **Configuración -> Números de WhatsApp** (solo soporte/superadmin; el
   operador no ve esta pantalla).
2. Llena el formulario: un **nombre** para identificarlo, el **Phone number ID** y el
   **WABA ID** (ambos te los da Meta, son solo números). El **token** y el **límite diario**
   NO se piden aquí: el sistema usa el token de la cuenta que ya configuraste en "Token de
   acceso WhatsApp" y el límite lo pone Meta.
3. Clic en **Verificar y guardar**. El sistema consulta a Meta para confirmar que el número
   existe y está aprobado **antes** de guardarlo. Si Meta lo rechaza (ID incorrecto o token
   de la cuenta inválido), no se guarda y te dice el motivo.
4. Ya guardado, aparece en la lista como **Activo** y entra al reparto de campañas.

En la lista, cada número tiene un botón para **Verificar** (reconsultar su estado y calidad en
Meta) y otro para **Activar/Desactivar** (para sacar de circulación un número con problemas sin
borrarlo).

> **Reemplazar un número quemado:** si un número baja de calidad, el sistema lo pausa solo
> (circuit breaker). Puedes **desactivarlo** aquí y dar de alta uno nuevo con estos mismos
> pasos, siempre que Meta permita el alta.

### Qué es el warm-up

Un número nuevo debe empezar con **poco volumen** e ir subiendo poco a poco - mandar miles de
mensajes desde el día 1 es la vía más rápida a un bloqueo. **El sistema lo hace solo:** cada
número nuevo arranca con un tope bajo y el sistema lo sube gradualmente cada día, según lo que
fue enviando con buena calidad, hasta el límite que Meta le da a la cuenta. Además, el sistema
**nunca envía más de lo que Meta permite** en total: si se llega al tope del día, frena y sigue
al día siguiente. Tú no configuras ni aceleras nada - es la protección que evita el bloqueo.

> Los primeros días el semáforo del número puede verse amarillo ("calidad pendiente"). Es normal.

**Nota sobre límites:** desde 2025 Meta cuenta el límite **por cuenta**, no por número.
Varios números bajo la misma cuenta **comparten** el tope diario - agregar números da más
redundancia y balanceo, pero no multiplica el volumen total. Meta sube este límite solo
cuando la cuenta manda con buena calidad. El panel muestra el límite actual de la cuenta en
el semáforo del Panel ("Límite de la cuenta (Meta)"), que se refresca cada vez que abres o
actualizas el Panel, y además una vez al día por su cuenta - así, si Meta sube el límite,
el sistema lo aprovecha aunque nadie entre al panel. Soporte no tiene que tocar nada.

---

## 9. Agregar un número de prueba (sandbox)

**Cuándo se necesita:** en la cuenta de pruebas, un compañero no recibe los mensajes de
prueba del panel. Es porque la cuenta sandbox de Meta **solo permite enviar a números
registrados** antes.

**Pasos:**

1. Entra a **developers.facebook.com** con la cuenta del administrador.
2. Selecciona la app del proyecto (`wa-api-test`).
3. Menú izquierdo: **WhatsApp -> Configuración de API**.
4. Ve a **"Números de teléfono de destinatario"** -> **Agregar número de teléfono**.
5. Escribe el número en formato internacional (ej: `+52 923 131 1146`).
6. Llega un código por WhatsApp - escríbelo en pantalla para verificar.
7. Ya verificado, el número puede recibir mensajes de prueba.

> Esta restricción es solo en sandbox (pruebas). En la cuenta de producción del negocio
> se puede enviar a cualquier número (respetando permiso, baja y horario).

---

## 10. Verificar el negocio en Meta (Business Verification)

**Qué es:** Meta exige comprobar que el negocio es real antes de dejar enviar a gran
escala. Sin esta verificación, la cuenta queda topada en 250 mensajes/día en total.

**Ojo: hay 3 tipos de "verificado" en Meta y NO son lo mismo:**

| Tipo | Dónde se ve | ¿Sirve para WhatsApp API? |
|---|---|---|
| Cuenta personal de FB verificada | Perfil personal | No |
| Página de FB verificada (palomita azul) | Fan page pública | No |
| **Business Manager verificado** | Business Manager -> Configuración -> Información del negocio | **Sí, esta es la que se necesita** |

**Cómo confirmar si ya está:**

1. Entra a **business.facebook.com**.
2. **Configuración del negocio -> Información del negocio**.
3. Busca el campo **"Verificación del negocio"**:
   - Dice **"Verificado"** (palomita verde) -> listo, no hay que subir nada.
   - Dice **"Sin verificar"** o **"Pendiente"** -> falta, aunque la página o el perfil
     personal estén verificados.

**Si falta - documentos que pide Meta (México):**

- **RFC** - el más común, generalmente suficiente.
- O Acta Constitutiva / Cédula de Identificación Fiscal.

El administrador los sube directo en Business Manager. Meta tarda **3 a 10 días hábiles**.

> Se hace **una sola vez**. Ya aprobada, aplica a todos los números y apps bajo ese
> Business Manager.

---

## 11. Interpretar las alertas de Business Manager

Meta muestra alertas en **business.facebook.com** cuando algo necesita atención. Qué
significa cada una y qué hacer:

| Alerta | Qué significa | Qué hacer |
|---|---|---|
| **Calidad del número: Baja (rojo)** | Muchos bloqueos o reportes de spam | El sistema ya paró los envíos de ese número solo. No reanudar hasta que suba a Media. Revisar qué campañas recientes lo causaron. |
| **Calidad del número: Media (amarillo)** | Algunos bloqueos o mensajes no leídos. En riesgo | Continuar con cuidado. Bajar volumen si la tendencia empeora. |
| **Límite de mensajes alcanzado** | Se llegó al tope diario del tier | Normal. Los pendientes se encolan para el día siguiente solos. |
| **Cuenta en revisión / Restricción temporal** | Meta está revisando la cuenta | **Parar todos los envíos de inmediato** y avisar a soporte. No enviar mientras dure la revisión. |
| **Plantilla rechazada** | Meta no aprobó una plantilla | No afecta la cuenta. Esa plantilla no se puede usar. Revisar el motivo y cambiar el contenido antes de reenviar (nunca reenviar igual). |
| **Actualización de políticas** | Meta cambió sus términos | Leer y aceptar si aplica. Soporte revisa si afecta al sistema. |
| **Token expirado / Permiso revocado** | Se cortó el acceso a la API | Seguir la sección 7 para generar y pegar un token nuevo. |

> Si aparece una alerta que no está aquí: **anota el mensaje exacto y avisa a soporte antes
> de tocar nada.**

---

## 12. Códigos de error y qué hacer

El panel puede registrar estos códigos de error de Meta en los envíos. Qué significan y la
acción correcta:

| Código | Significado | Qué hacer |
|---|---|---|
| **131026** | El número no existe en WhatsApp | El sistema lo marca inválido y no reintenta. Nada que hacer a mano. |
| **131048** | Restricción de envíos del número (spam/bloqueos) | El sistema pausa el número 1 hora solo. |
| **131049** | El **destinatario** llenó su cupo de mensajes de marketing (no es problema del número) | El sistema marca ese mensaje como fallido y **no le reintenta a ese contacto por 24h** (lo que pide Meta). **No pausa el número** - los demás contactos siguen normal. |
| **131050** | El destinatario se dio de **baja** de marketing desde su WhatsApp | El sistema lo marca como Baja solo (ver sección 4). No hay que hacer nada. |
| **131064** | La **cuenta** llegó a su límite por categorización de plantillas | El sistema pausa el número. Avisar a soporte para revisar las categorías de las plantillas en Business Manager. |
| **368** | Cuenta bloqueada temporalmente | **Parar TODO y revisar Business Manager.** Avisar a soporte. |
| **190** | Token expirado | Renovar el token (sección 7) antes de seguir. |
| **132001** | Plantilla no aprobada | Revisar el estado de la plantilla en Meta. Solo se usan las aprobadas. |

> La mayoría de estos el sistema los maneja solo (pausa el número, marca inválido). Tu
> papel es reconocerlos si aparecen en un reporte y, en los que dicen "parar", no forzar
> los envíos.

---

## 13. Preguntas frecuentes

**¿Puedo escribir el nombre de una plantilla a mano en una campaña?**
No. El panel solo deja elegir plantillas que Meta ya aprobó. Es a propósito: evita mandar
una plantilla no aprobada y que penalicen la cuenta.

**Creé un botón de baja pero el sistema no da de baja a la gente. ¿Por qué?**
Casi seguro el título del botón no coincide con las palabras que el sistema entiende.
Recuerda que al tocar un botón, WhatsApp responde con el título del botón. La baja se
dispara solo con `STOP`, `BAJA`, `CANCELAR` o `NO` exactos (ver sección 4). Un botón "No,
gracias" no sirve para baja. Si quieres otra palabra, pídesela a soporte.

**¿El botón "no por ahora" da de baja al contacto?**
No. "No por ahora" es Pospuesto: solo pausa las campañas de WhatsApp un tiempo, después se
le puede volver a escribir. La baja es otra cosa (permanente, palabras de la sección 4). No
los confundas.

**¿El SMS necesita que Meta apruebe algo?**
No. El SMS no pasa por Meta. Es texto plano que se escribe en el panel. Pero igual lleva
identificación del remitente y opción de baja (sección 5).

**¿Cada cuánto renuevo el token?**
Normalmente nunca: el System User Token no expira. Solo si ves el código 190 (token
expirado) o alguien revocó los permisos. Sigue la sección 7.

**Meta me rechazó una plantilla. ¿La vuelvo a mandar igual?**
No. Reenviar el mismo contenido rechazado baja la reputación de la cuenta. Cambia el
texto o espera 24h.

**¿Agregar más números me deja enviar el doble?**
No necesariamente. Desde 2025 el límite es por cuenta, no por número. Varios números
comparten el tope diario. Sirven para redundancia y balanceo, no para multiplicar volumen.

**Salió una alerta rara en Business Manager y no sé qué es.**
Anota el texto exacto y avisa a soporte antes de hacer nada. No improvises con la cuenta
de Meta.
