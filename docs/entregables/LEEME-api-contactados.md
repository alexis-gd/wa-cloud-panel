# API de contactados - Paquete de entrega

Para el equipo técnico de Prestamaz.

Este API responde una sola pregunta: **¿a quién le escribimos en tal fecha?** Sirve para que
el sistema de Prestamaz cruce esa información con la suya sin pedirla a mano.

---

## Qué recibes

| Archivo | Para qué |
|---|---|
| Este `LEEME` | Cómo comprobar que funciona en 5 minutos |
| `api-contactados.postman_collection.json` | Colección de Postman lista para correr |
| `api-contactados.md` | La documentación completa: parámetros, respuestas y errores |
| La llave de acceso | Se entrega **por separado**, no viene en estos archivos |

---

## Comprobar que funciona (5 minutos)

### Con Postman

1. Abre Postman → **Import** → arrastra `api-contactados.postman_collection.json`.
2. Clic en la colección → pestaña **Variables**.
3. En `api_key`, pega la llave que te dieron.
4. En `fecha_con_datos`, pon un día en el que sepas que hubo envíos.
5. **Save** (Ctrl+S).
6. Clic derecho en la colección → **Run collection** → **Run**.

> ⚠️ Llena la columna **CURRENT VALUE**, no solo *Initial Value*. Postman manda la primera, y
> si la dejas vacía las peticiones salen con la variable sin resolver y fallan por formato.

**Qué debes ver:** las cuatro primeras peticiones en verde, y las cuatro de la carpeta
*Errores* también en verde - ésas comprueban que el API **rechaza** lo que debe rechazar.

Si todo pasa, el API está funcionando de tu lado.

### Con curl, si prefieres

```bash
LLAVE="la-llave-que-te-dieron"
FECHA="2026-08-24"

# 1. Un día
curl -s "https://sender.prestamaz.site/api/contacted?date=$FECHA" \
  -H "X-API-Key: $LLAVE"

# 2. Un rango (incluye las dos fechas)
curl -s "https://sender.prestamaz.site/api/contacted?from=2026-08-01&to=2026-08-31" \
  -H "X-API-Key: $LLAVE"

# 3. Paginado
curl -s "https://sender.prestamaz.site/api/contacted?date=$FECHA&per_page=50&page=1" \
  -H "X-API-Key: $LLAVE"

# 4. Sin llave -> debe responder 401
curl -s -o /dev/null -w "%{http_code}\n" \
  "https://sender.prestamaz.site/api/contacted?date=$FECHA"
```

Respuesta esperada de la primera:

```json
{
  "status": "ok",
  "data": [
    { "name": "Juan Pérez", "phone": "529231311146" },
    { "name": null,         "phone": "526692522844" }
  ],
  "meta": {
    "from": "2026-08-24", "to": "2026-08-24",
    "total": 583, "page": 1, "per_page": 1000, "pages": 1
  }
}
```

---

## Cuatro cosas que conviene saber de entrada

Son las que más confunden al integrar.

### 1. Un contacto sale UNA vez por día

Aunque haya recibido tres mensajes. Si tu sistema espera una fila por mensaje, los números
no te van a cuadrar y vas a pensar que faltan datos.

### 2. Las fechas son días de México, no UTC

Un mensaje enviado a las **23:30 del 17** pertenece al **17**, aunque en UTC ya sea 18.

### 3. Máximo 60 peticiones por minuto

Por IP. Al pasarte responde **429**. No consultes en ciclo cerrado: usa el paginado.

### 4. "Contactado" significa que el mensaje salió

Cuentan los estados `sent`, `delivered` y `read`. Un mensaje fallido o descartado **no**
aparece: a esa persona no se le contactó.

Cuenta **los dos canales**, WhatsApp y SMS.

---

## Errores que te puedes encontrar

| Código HTTP | `code` | Qué pasó |
|---|---|---|
| 401 | `UNAUTHORIZED` | Falta la cabecera `X-API-Key`, o la llave no coincide |
| 422 | `MISSING_DATE` | No mandaste `date` ni `from`/`to` |
| 422 | `AMBIGUOUS_RANGE` | Mandaste `date` **y** rango a la vez |
| 422 | `INVALID_RANGE` | La fecha final es anterior a la inicial |
| 422 | `INVALID_PARAMS` | Una fecha mal escrita, o `page`/`per_page` inválidos |
| 429 | - | Más de 60 peticiones en un minuto |

**Todos** responden con la misma forma, sin excepción:

```json
{ "status": "error", "message": "...", "code": "..." }
```

Puedes programar contra `code` y mostrar `message` tal cual: viene en español y dice qué
corregir. No hay ninguna respuesta con otro formato.

---

## Sobre la llave

- Va en la cabecera **`X-API-Key`**, no en la URL.
- Es un secreto: no la subas a un repositorio ni la mandes por chat.
- Si se filtra, avísanos: se cambia y se te entrega una nueva. Nada más del sistema se ve
  afectado.
---

## Dudas

Escríbenos con: la **URL completa** que llamaste (sin la llave), el **código HTTP** que te
respondió y el **cuerpo de la respuesta**. Con eso se resuelve casi siempre a la primera.

---

> **Nota interna - borrar antes de enviar.**
>
> Este paquete son dos archivos de esta carpeta más `docs/api-contactados.md`, que vive fuera
> a propósito: es la fuente única y se actualiza con el código. No la dupliques aquí.
>
> **Para mandarla en PDF:**
>
> ```bash
> php artisan guias:build docs/api-contactados.md
> ```
>
> Genera `public/doc/api-contactados.html`. Ábrelo en Chrome y usa el botón
> **"Imprimir / Guardar PDF"** de arriba a la derecha. En el diálogo: *Destino* → Guardar como
> PDF, y activa **"Gráficos de fondo"** para que se vean las tablas y los bloques de código.
>
> Usa la misma plantilla que las guías del cliente, así que sale con el mismo formato y no hay
> que instalar nada. El HTML generado está gitignoreado: es de un solo uso.
