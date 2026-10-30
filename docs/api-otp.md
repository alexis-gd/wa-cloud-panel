# API de códigos de verificación (OTP) por WhatsApp

API para que la app de Prestamaz mande un **código de un solo uso** al WhatsApp del usuario y
después lo verifique. No la usa el panel: la consume el servidor de la app.

Son dos llamadas: una pide el código, la otra lo comprueba.

---

## Antes de empezar: cómo funciona

1. Tu servidor llama a **`/api/otp/send`** con el número del usuario.
2. Nosotros generamos un código de **6 dígitos**, lo mandamos por WhatsApp y **no te lo
   devolvemos**.
3. El usuario lee el código en su WhatsApp y lo escribe en tu app.
4. Tu servidor llama a **`/api/otp/verify`** con el número y lo que escribió el usuario.
5. Te contestamos si coincide o no.

> **El código nunca viaja en la respuesta, y eso es a propósito.** Si te lo devolviéramos, el
> OTP dejaría de probar que la persona tiene ese teléfono en la mano: bastaría con tener la
> llave del API. Lo único que sabe el código es el usuario, en su WhatsApp.

> **El código dura 10 minutos exactos.** El mensaje que le llega al usuario también lo dice. Si
> tu app muestra otro número en pantalla, el usuario va a leer una cosa y le va a pasar otra.

---

## Probarlo en Postman (5 minutos)

Junto a este documento viene **`api-otp.postman_collection.json`**.

1. Postman → **Import** → arrastra el archivo.
2. Clic en la colección → pestaña **Variables**:
   - `api_key` → la llave que te dieron
   - `telefono` → un celular de 10 dígitos que **tenga WhatsApp** y al que puedas ver los mensajes
3. **Save** (Ctrl+S).

Las de la carpeta **Errores** se pueden correr de golpe: fallan a propósito y sus pruebas
verifican justo eso. Verde = el API rechaza lo que debe rechazar.

Las dos principales van **a mano y en orden**, porque entre una y otra tienes que leer tu
WhatsApp:

1. **"1. Pedir un codigo"** → debe dar 201. Una de sus pruebas comprueba que el código **no**
   viene en la respuesta.
2. Lee el código que llegó → ponlo en la variable `codigo` → **Save**.
3. **"2. Verificar el codigo"** → debe dar 200 con `verified: true`.

> ⚠️ **Postman guarda dos columnas por variable: *Initial value* y *Current value*. La que se
> manda es Current.** Si escribes el código en Initial, la petición sale con el `000000` del
> default y responde `INVALID_CODE` - y cada intento fallido gasta uno de los 5. La colección
> te detiene antes de mandarlo si detecta que la variable sigue en ceros.

> ⚠️ **No corras la colección completa de un jalón.** Hay un freno de 1 minuto entre códigos al
> mismo número, así que el segundo envío respondería `429 TOO_SOON`. Es correcto, pero sale en
> rojo y parece un error.

---

## Autenticación

Todas las llamadas llevan la cabecera **`X-API-Key`** con la llave que se te entregó. Es la
misma llave del API de contactados.

```
X-API-Key: la-llave-que-te-dieron
```

Sin ella, o con una equivocada, la respuesta es **401**.

---

## 1. Pedir un código

```
POST https://sender.prestamaz.site/api/otp/send
Content-Type: application/json
X-API-Key: la-llave-que-te-dieron
```

```json
{
  "phone": "9231311146",
  "reference": "login-8832"
}
```

| Campo | Obligatorio | Qué es |
|---|---|---|
| `phone` | Sí | Celular del usuario. A 10 dígitos o con el `52` adelante, como prefieras. No mandes el `+`. |
| `reference` | No | Un identificador tuyo (hasta 100 caracteres) para amarrarlo a tu propio registro. Te lo devolvemos tal cual y sale en el panel. |

**Respuesta correcta (201):**

```json
{
  "status": "ok",
  "data": {
    "id": 1482,
    "reference": "login-8832",
    "expires_in": 600
  }
}
```

`expires_in` son **segundos**. Úsalo para el contador de tu pantalla en vez de escribir 600 a
mano: si algún día cambia, tu app se entera sola.

---

## 2. Verificar el código

```
POST https://sender.prestamaz.site/api/otp/verify
Content-Type: application/json
X-API-Key: la-llave-que-te-dieron
```

```json
{
  "phone": "9231311146",
  "code": "483920"
}
```

**Respuesta correcta (200):**

```json
{
  "status": "ok",
  "data": { "verified": true }
}
```

Un código verificado **no sirve dos veces**. Si tu app necesita verificar otra vez, pide uno
nuevo.

---

## Errores

Todos salen con la misma forma, en español, con un `code` fijo que puedes comparar en tu
código. **Compara contra `code`, no contra `message`**: el texto puede mejorarse con el tiempo,
el `code` no cambia.

```json
{
  "status": "error",
  "message": "El codigo no coincide.",
  "code": "INVALID_CODE"
}
```

### Al pedir un código

| HTTP | `code` | Qué pasó | Qué hacer |
|---|---|---|---|
| 401 | - | Falta la llave o no es válida | Revisar la cabecera `X-API-Key` |
| 422 | `INVALID_PARAMS` | Falta `phone` o viene mal formado | Revisar el cuerpo de la petición |
| 422 | `INVALID_PHONE` | El número no es un celular de México | Pedirle al usuario que lo corrija |
| 429 | `TOO_SOON` | Ya se mandó un código a ese número hace menos de 1 minuto | Esperar. La respuesta trae `retry_after` en segundos |
| 429 | `TOO_MANY_REQUESTS` | Ese número pidió más de 10 códigos en 24 horas | Es un freno antiabuso. Al día siguiente vuelve a funcionar |

### Al verificar

| HTTP | `code` | Qué pasó | Qué hacer |
|---|---|---|---|
| 422 | `INVALID_PARAMS` | Falta `code` o no son 6 dígitos | Revisar el cuerpo |
| 422 | `NOT_FOUND` | No hay ningún código pendiente para ese número | Pedir uno nuevo |
| 422 | `EXPIRED` | Pasaron los 10 minutos | Pedir uno nuevo |
| 422 | `INVALID_CODE` | El código no coincide | Dejar que lo intente otra vez |
| 422 | `TOO_MANY_ATTEMPTS` | 5 intentos fallidos con el mismo código | Pedir uno nuevo. El viejo ya no sirve |

> **Por qué hay tope de intentos.** Seis dígitos son un millón de combinaciones: sin tope, un
> programa las prueba todas. Con 5 intentos, la probabilidad de atinar es de 5 entre un millón.

---

## Lo que no te podemos garantizar

**Que el mensaje llegue.** Eso depende de WhatsApp y del teléfono del usuario. Lo que sí
podemos decirte es si salió: esa información está en el panel, pantalla **Códigos**, con el
número, tu `reference` y el motivo si falló.

Casos en los que el mensaje no sale, y no hay forma de saberlo antes de intentarlo:

- El número no tiene WhatsApp.
- La cuenta de WhatsApp de Prestamaz llegó a su límite diario de Meta ese día.

En los dos casos la petición `/send` ya te contestó **201**: el envío ocurre enseguida, no
dentro de la misma llamada. Si un usuario reporta que no le llegó, la pantalla **Códigos** del
panel dice qué pasó.

---

## Recomendaciones para tu app

- **Un botón de "reenviar" con cuenta regresiva.** Nuestro freno es de 1 minuto entre códigos;
  si tu botón se puede picar antes, el usuario va a ver un error en vez de un contador.
- **No guardes el código en tu lado.** No lo tienes, y no lo necesitas: nosotros lo
  verificamos.
- **Usa `reference`.** Cuando alguien llame diciendo que no le llegó, es lo que vuelve
  inmediata la búsqueda en el panel.
- **El `52` lo ponemos nosotros.** Manda los 10 dígitos tal como el usuario los escribe.

---

## Preguntas frecuentes

**¿Puedo pedirle un código a un número que se dio de baja de la publicidad?**
Sí. La baja es de publicidad; el código lo está pidiendo el usuario en ese momento para entrar
a su cuenta. Son cosas distintas y se tratan distinto.

**¿Funciona a cualquier hora?**
Sí. Las campañas de publicidad tienen horario; los códigos no. Un usuario que entra a su cuenta
a las 3 de la mañana recibe su código a las 3 de la mañana.

**¿Qué pasa si pido dos códigos seguidos al mismo número?**
El primero deja de servir en cuanto se genera el segundo. Siempre vale el último.

**¿El código llega con un botón?**
Sí, un botón de **Copiar código**. El usuario lo toca y lo pega en tu app.
