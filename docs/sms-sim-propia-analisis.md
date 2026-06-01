# SMS por SIM propia — Análisis técnico, legal y económico

> Análisis honesto de la opción "enviar SMS desde SIM propia" para wa-cloud-panel.
> **Conclusión adelantada**: técnicamente posible y legalmente viable bajo condiciones específicas, pero económica y operativamente inviable a 200K/mes para campañas de marketing.

---

## La pregunta del cliente

> "¿Hay manera de hacerlo con algún sistema que lo haga por medio de SIM, o sea nosotros mismos enviar los mensajes?"

Sí. Hay tres formas de hacerlo y proyectos open source maduros para cada una. Las barreras reales son tres: **regulatoria**, **contractual con operadores**, y **económica/operativa**.

---

## Las 3 formas técnicas

### Opción 1: Android como gateway (la más moderna)

Un teléfono Android con app que recibe peticiones HTTP de tu servidor Laravel y envía los SMS por su SIM.

**Proyectos open source verificados (abril 2026):**

- **SMS Gateway for Android™** — `github.com/capcom6/android-sms-gateway` (3.3K stars, mantenido)
  - Cliente PHP oficial: `github.com/android-sms-gateway/client-php`
  - Servidor self-hosted: `github.com/android-sms-gateway/server`
  - JWT auth, webhooks, modo público y privado, encriptación end-to-end
- **httpSMS** — `github.com/NdoleStudio/httpsms` (1.2K stars)
  - Docs: `docs.httpsms.com`
- **textbee** — `github.com/vernu/textbee` (1.9K stars)

**Ejemplo PHP** (capcom6/client-php):
```php
$client = new \AndroidSmsGateway\Client('login', 'password');
$message = new \AndroidSmsGateway\Domain\Message(
    'Hola, soy Prestamaz. STOP para baja',
    ['+5219211234567']
);
$response = $client->send($message);
```

### Opción 2: Módem GSM USB

Dongle USB con SIM controlado por comandos AT desde el servidor.

- Hardware: Huawei E173/E303/E3531 (~$300-500 MXN), módulos SIMCom SIM800/SIM900
- Software: Gammu/Gammu-SMSD, Jasmin SMS Gateway, `pentagridsec/smsgate` (Python)

### Opción 3: Pool de varios celulares Android

Para volúmenes mayores: varios celulares con SIMs independientes, todos contra un servidor central. SMS Gateway for Android lo soporta nativamente como "device pool".

---

## El marco legal real en México

> Esta sección está corregida con fuentes oficiales del DOF, IFT y PROFECO.
> Antes había afirmaciones imprecisas; aquí va lo que efectivamente dice cada documento.

### LFPDPPP (Ley Federal de Protección de Datos Personales en Posesión de los Particulares)

**A quién aplica**: a tu cliente directamente (cualquier empresa privada que trate datos personales, incluyendo números de celular).

**Lo que exige**:
- Consentimiento del titular para usar sus datos con fines mercadotécnicos o publicitarios
- Aviso de privacidad disponible al titular
- Mecanismo para que el titular revoque su consentimiento (derechos ARCO)

**Riesgo si se incumple**: multas administrativas del INAI, demandas civiles del titular.

**Fuente oficial**: `diputados.gob.mx/LeyesBiblio/pdf/LFPDPPP.pdf`

### NOM-184-SCFI-2018

**A quién aplica**: a los **proveedores de servicios de telecomunicaciones** (Telcel, AT&T, Movistar, etc.) en su relación con sus consumidores. **NO aplica directamente a tu cliente** que envía SMS comerciales.

**Lo que dice el artículo 4.9**:
> "Los Proveedores de Servicios de Telecomunicaciones, deben abstenerse de realizar llamadas o enviar mensajes de texto a los Consumidores a los que les provean servicios de telecomunicaciones, promoviendo cualquier tipo de servicio de telecomunicaciones adicional al ya contratado, paquete, nuevo plan o producto (propio o de terceros), así como publicidad de terceros, a menos que los Consumidores manifiesten su consentimiento expreso..."

**Lectura correcta**: Esta norma regula a Telcel/AT&T/Movistar para que no manden publicidad a sus propios usuarios sin consentimiento. **No es una norma general que prohíba a empresas como tu cliente enviar SMS comerciales**.

**Aclaración importante**: en mi análisis anterior presenté esta norma como si aplicara a tu cliente. Era incorrecto.

**Fuente oficial**: DOF, 8 de marzo de 2019 — `dof.gob.mx/nota_detalle.php?codigo=5552286&fecha=08/03/2019`

### REPEP (Registro Público para Evitar Publicidad)

**A quién aplica**: a cualquier empresa que envía publicidad por SMS, llamadas o correo a consumidores. Aplica a tu cliente.

**Lo que exige**:
- Consultar el REPEP antes de enviar publicidad
- No contactar a números registrados en el REPEP
- 30 días para dejar de contactar a un número que se registró recientemente

**Riesgo si se incumple**: PROFECO puede multar.

**Fuente oficial**: `repep.profeco.gob.mx`

### LFTR (Ley Federal de Telecomunicaciones y Radiodifusión)

**A quién aplica**: regula a operadores y a la interconexión entre redes. No regula directamente a empresas que envían SMS comerciales como usuarios finales.

**Relevancia para tu caso**: la LFTR es la base de la regulación del IFT sobre tarifas de interconexión SMS, rutas A2P (Application-to-Person), y prácticas prohibidas en el Convenio Marco de Interconexión.

**Fuente oficial**: `diputados.gob.mx/LeyesBiblio/pdf/LFTR.pdf`

### Convenio Marco de Interconexión Telcel 2023 (CMI 2023)

**Lo que dice oficialmente**: regula prácticas prohibidas entre operadores en el envío de SMS hacia redes de terceros. Establece obligaciones de detección y prevención de "Prácticas Prohibidas" en SMS A2P.

**Relevancia**: este convenio es entre operadores. No aplica directamente a tu cliente. Sin embargo, los términos de servicio de cada operador (Telcel, AT&T, Movistar) hacia sus usuarios sí pueden incluir cláusulas sobre uso de la línea para envíos masivos.

**Fuente oficial**: `ift.org.mx` (buscar "CMI Telcel 2023" o "Convenio Marco de Interconexión")

---

## El marco contractual: términos de servicio de cada operador

Aquí entra el riesgo real para SIM propia. **No es ley pública sino contrato privado entre tú y la operadora**.

### Lo que SÍ es verificable

Cuando contratas una línea Telcel/AT&T/Movistar (prepago o postpago), aceptas su contrato de adhesión. Estos contratos típicamente incluyen cláusulas como:
- Uso "personal y no comercial" de la línea
- Prohibición de "uso atípico" o "uso indebido"
- Derecho del operador a suspender el servicio si detecta patrones anómalos

**Cómo verificarlo tú mismo**:
- Telcel: `telcel.com` → buscar "Contrato de adhesión Telcel"
- AT&T: `att.com.mx` → "Términos y condiciones"
- Movistar: `movistar.com.mx` → "Aviso legal y contratos"

### Lo que NO te puedo afirmar con fuente oficial

En mi análisis anterior afirmé:

❌ "Telcel requiere que las instituciones financieras firmen contrato directo para SMS"
   → Esto lo leí en un blog de un proveedor SMS competidor (Instasent), no encontré documento oficial de Telcel ni de regulación pública que lo establezca.

❌ "Los operadores definen como spam el envío de 10+ mensajes con el mismo contenido en 1 minuto"
   → Misma fuente. Es lo que ELLOS describen como práctica de los carriers, no es definición legal pública.

❌ "La SIM se bloquea automáticamente"
   → Comportamiento documentado en foros y casos de usuarios, pero NO encontré documento oficial de Telcel/AT&T que lo establezca explícitamente como política pública.

❌ "Para empresa de préstamos hay escrutinio adicional de CONDUSEF/PROFECO"
   → Razonamiento mío basado en que las financieras están más reguladas. No encontré normativa que diga "SMS de fintech tienen reglas especiales".

**Estas afirmaciones pueden ser ciertas en la práctica pero no las puedo respaldar con fuente oficial pública.** Lo correcto es presentarlas como **riesgo razonable basado en práctica comercial reportada**, no como prohibición legal.

---

## Lo que SÍ se puede afirmar con confianza

1. **Para enviar SMS marketing a 200K personas necesitas consentimiento previo de cada una** (LFPDPPP). Esto aplica sin importar el medio técnico.

2. **Debes consultar el REPEP antes de cada envío masivo** y excluir a los registrados.

3. **Tu aviso de privacidad debe mencionar el envío de SMS comerciales** y los titulares pueden revocar el consentimiento.

4. **Los términos de servicio de los operadores (contrato privado)** generalmente prohíben el uso comercial masivo de líneas residenciales/personales. Esto puede llevar a suspensión de la SIM por decisión unilateral del operador, no por ley.

5. **Los operadores aplican filtros técnicos anti-spam** que pueden hacer que tus mensajes no se entreguen aunque tu sistema diga "enviado". Esto no es ilegal, es política operativa.

6. **Los proveedores legales de SMS masivo** (SMS Masivos, Twilio, etc.) tienen contratos comerciales con los operadores que les permiten hacer envíos masivos por rutas autorizadas.

---

## El problema operativo (volumen)

### Velocidad real por SIM

| Hardware | SMS/min realista | SMS/hora | SMS/día (10h) |
|---|---|---|---|
| 1 celular Android | 5-10 | 300-600 | 3,000-6,000 |
| 1 módem GSM USB | 6-10 | 360-600 | 3,600-6,000 |
| Pool de 10 celulares | 50-100 | 3,000-6,000 | 30,000-60,000 |
| Pool de 30 celulares | 150-300 | 9,000-18,000 | 90,000-180,000 |

Tu meta de **200K/mes** ≈ **6,667/día** ≈ **277/hora**.

Para hacer eso sin disparar filtros anti-spam de los operadores necesitas mínimo **5-8 celulares con sus SIMs activas** funcionando en simultáneo.

### Costos reales

- 5-8 celulares Android nuevos económicos: **$15,000-25,000 MXN inicial**
- 5-8 SIMs prepago con planes de SMS: **$1,000-3,200 MXN/mes**
- Servidor con conectividad estable: **$500-1,500 MXN/mes**
- Mantenimiento manual (SIMs bloqueadas, recargas, monitoreo): tiempo significativo
- **Sin CFDI**: las recargas a personas físicas no son deducibles como servicio empresarial

**Año 1**: ~$30,000-50,000 MXN inversión inicial + ~$2,000-5,000 MXN/mes operación.

### Comparativa con SMS Masivos cotizado

A 200K SMS/mes con SMS Masivos (cotización personalizada para alto volumen, estimado $0.30-0.40 MXN/msg):
- **Costo**: ~$60,000-80,000 MXN/mes
- CFDI 4.0 automático
- Rutas autorizadas con todos los operadores
- Tasa de entrega 98-99% reportada por el proveedor
- API REST + webhooks + sandbox para desarrollo
- Cero mantenimiento de hardware
- Cero riesgo contractual con operadores

---

## Tabla comparativa final (versión corregida)

| Aspecto | SIM propia (Android pool) | SMS Masivos (proveedor) |
|---|---|---|
| **Marco regulatorio** | Mismo: LFPDPPP + REPEP aplican igual | Mismo: LFPDPPP + REPEP aplican igual |
| **Marco contractual con operador** | Riesgo: contrato privado de cada SIM puede prohibir uso masivo | Sin riesgo: el proveedor tiene contratos comerciales con operadores |
| **Inversión inicial** | $15K-25K MXN | $0 |
| **Costo mensual 200K** | ~$2K-5K MXN operación + bloqueos esperables | ~$60K-80K MXN cotización |
| **Tasa de entrega real** | Variable, no auditable, dependerá de filtros | 98-99% reportada |
| **Velocidad** | Limitada por SIM (~10/min c/u) | 100+ SMS/segundo |
| **Delivery receipts** | Limitados, no estándar | DLR completo vía webhook |
| **Riesgo de bloqueo SIM** | Existe (decisión del operador) | Cero |
| **CFDI** | No (recargas personales no deducibles como servicio) | Sí, 4.0 automático |
| **Mantenimiento** | Constante (SIMs, recargas, hardware) | Cero |
| **Escalabilidad** | Lineal (más volumen = más hardware) | Sin límite técnico |
| **Tiempo desarrollo** | 2-3 semanas (multi-device) | 3-5 días (API REST) |

---

## Casos donde SIM propia SÍ tiene sentido

Para que conste, hay escenarios razonables:

1. **OTP transaccional bajo volumen** (<500/día) para clientes que ya dieron consentimiento.
2. **Notificaciones internas** entre empleados de la empresa (uso interno, no marketing).
3. **Sistemas de monitoreo** que mandan alertas a 1-5 números fijos del equipo técnico.
4. **Proyectos en etapa de prueba** sin presupuesto, con volúmenes <100/día.

**Ninguno aplica para wa-cloud-panel** (marketing masivo a 200K prospectos mensuales).

---

## Recomendación honesta para presentar al cliente

> "Sí, técnicamente es posible enviar SMS desde una SIM propia. Existen proyectos open source maduros (SMS Gateway for Android, httpSMS) y se podría montar con varios celulares Android. Sin embargo, hay tres consideraciones importantes:
> 
> **Marco legal**: tanto si usamos SIM propia como un proveedor, la LFPDPPP exige consentimiento de cada destinatario y el REPEP exige consultar el registro antes de cada envío. Eso aplica igual en ambos casos. La NOM-184 que regula publicidad por SMS aplica a operadores hacia sus clientes, no directamente a nuestro cliente.
> 
> **Marco contractual con operadores**: cuando contratas una SIM Telcel/AT&T/Movistar aceptas un contrato que típicamente prohíbe el uso comercial masivo. Si el operador detecta el patrón puede suspender la línea por decisión unilateral. Esto no es ley pública sino términos de servicio privados, pero es riesgo real reportado.
> 
> **Operativo y económico**: para 200K/mes necesitamos 5-8 celulares + SIMs corriendo 24/7. La tasa real de entrega depende de filtros anti-spam técnicos que aplican los operadores. Inversión inicial ~$25K MXN + operación constante. Comparado con SMS Masivos a ~$60-80K/mes con CFDI, rutas autorizadas, 98-99% de entrega reportada, sandbox gratis y soporte en español.
> 
> **Lo que recomiendo**: usar SMS Masivos. Es empresa mexicana con CFDI 4.0, conexión legal interconectada con operadores, sandbox gratis para desarrollar, y a tu volumen el precio cotizado baja del público. Si el cliente quiere reducir costos al máximo, vale la pena cotizar 200K+ con varios proveedores (SMS Masivos, masmensajes.mx) y comparar."

---

## Si aún así el cliente insiste en SIM propia

Plan técnico con menor exposición:

### Setup recomendado
1. **SMS Gateway for Android™** en modo privado (self-hosted, no por sus servidores)
2. Pool de 8 celulares Android económicos (~$2,500 MXN c/u)
3. SIMs prepago de varios operadores para distribuir riesgo
4. Servidor con FCM para push notifications
5. Rate limit estricto: máximo 8 SMS/min por SIM
6. Rotación automática entre SIMs
7. Monitoreo: si una SIM deja de entregar, sacarla del pool de inmediato

### Lo que SÍ se puede hacer con SIM propia sin alta exposición
- Mensajes a clientes que **ya tienen relación contractual activa** con la empresa
- Mensajes transaccionales (recordatorio de pago, vencimiento, saldo)
- Volumen bajo (<2,000/día total)
- Consentimiento explícito firmado de cada destinatario

### Riesgos al usarlo para marketing 200K/mes
- **Contractual**: violación de términos de servicio del operador → suspensión de la SIM
- **Regulatorio LFPDPPP**: si no tienes consentimiento demostrable de cada destinatario → multa INAI
- **REPEP**: si envías a registrados → multa PROFECO
- **Reputacional**: para una empresa de préstamos, los SMS masivos no consentidos son un patrón típico de fraude que daña marca

---

## Fuentes oficiales para verificar

| Tema | Fuente oficial | Link |
|---|---|---|
| NOM-184-SCFI-2018 (texto completo) | DOF, 8 de marzo de 2019 | dof.gob.mx/nota_detalle.php?codigo=5552286 |
| LFPDPPP | Cámara de Diputados (DOF) | diputados.gob.mx/LeyesBiblio/pdf/LFPDPPP.pdf |
| LFTR (Ley Telecomunicaciones) | Cámara de Diputados (DOF) | diputados.gob.mx/LeyesBiblio/pdf/LFTR.pdf |
| REPEP (cómo registrarse y qué es) | PROFECO oficial | repep.profeco.gob.mx |
| NOM-184 explicada por PROFECO | gob.mx oficial | gob.mx/profeco/es/articulos/nom-184... |
| Convenio Marco Interconexión Telcel 2023 | IFT | ift.org.mx (buscar "CMI Telcel 2023") |
| Resoluciones IFT sobre SMS A2P | IFT | ift.org.mx/conocenos/pleno (buscar resoluciones SMS) |

---

## Repos de referencia (verificados abril 2026)

| Proyecto | Stars | Última act. | Stack | Link |
|---|---|---|---|---|
| capcom6/android-sms-gateway | 3.3K | Apr 2026 | Kotlin + Go server + PHP client | github.com/capcom6/android-sms-gateway |
| android-sms-gateway/client-php | — | activo | PHP | github.com/android-sms-gateway/client-php |
| NdoleStudio/httpsms | 1.2K | Apr 2026 | Go + Android | github.com/NdoleStudio/httpsms |
| vernu/textbee | 1.9K | Apr 2026 | Next.js + Android | github.com/vernu/textbee |
| pentagridsec/smsgate | 186 | Apr 2026 | Python (módems GSM USB) | github.com/pentagridsec/smsgate |
| multiOTP/SMSGateway | — | activo | PHP class + Android | github.com/multiOTP/SMSGateway |

---

## Decisión sugerida

Llevar esta tabla al cliente y dejar que decida con información completa y precisa. Mi recomendación sigue siendo:

- **SMS Masivos** para producción (cotizar 200K+ para mejor precio)
- **Twilio trial o sandbox de SMS Masivos** para desarrollo
- **SIM propia** queda evaluada como referencia, no recomendada para 200K marketing/mes

La opción de SIM propia es viable solo si el cliente acepta los riesgos contractuales con operadores y tiene consentimiento demostrable de cada destinatario.
