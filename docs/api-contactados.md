# API de contactados por fecha

API para que un sistema externo del cliente pregunte **a quién contactamos** en una fecha o
en un rango. No la usa el panel: la consume otro servidor.

---

## Comprobar que funciona (5 minutos)

Junto a este documento viene **`api-contactados.postman_collection.json`**, una colección de
Postman que no solo hace las peticiones: **verifica sus resultados**.

1. Postman → **Import** → arrastra el archivo.
2. Clic en la colección → pestaña **Variables** → pon tu llave en `api_key`.
3. En `fecha_con_datos`, un día en el que sepas que hubo envíos. **Save**.
4. Clic derecho en la colección → **Run collection** → **Run**.

**Qué debes ver:** las cuatro peticiones normales en verde y las cinco de la carpeta
*Errores* también en verde. Esas cinco mandan cosas mal a propósito y comprueban que el API
las **rechace** como debe; llevan fechas fijas, así que funcionan aunque no configures nada.

Entre otras cosas verifica que ningún contacto se repita dentro de una página, que la página 2
no repita contactos de la página 1, y que cada error traiga su `code` correspondiente.

Si prefieres la terminal:

```bash
LLAVE="la-llave-que-te-dieron"
FECHA="2026-08-17"

# Debe responder 200 con la lista
curl -s "https://sender.prestamaz.site/api/contacted?date=$FECHA" -H "X-API-Key: $LLAVE"

# Debe responder 401 (sin la cabecera de la llave)
curl -s -o /dev/null -w "%{http_code}\n" "https://sender.prestamaz.site/api/contacted?date=$FECHA"
```

---

## Autenticación

Cabecera `X-API-Key` con el valor de `API_KEY` del `.env` del panel.

```
X-API-Key: <la llave>
```

Sin la cabecera, o con una llave que no coincide, responde **401**.

> La llave **no** es el token de Meta ni el de Sanctum. Es un secreto aparte, solo para este
> API. Si se filtra, se cambia `API_KEY` en el `.env` y se avisa al cliente.

**Límite:** 60 peticiones por minuto por IP.

---

## Petición

```
GET https://sender.prestamaz.site/api/contacted?date=2026-08-17
GET https://sender.prestamaz.site/api/contacted?from=2026-08-01&to=2026-08-17
```

| Parámetro | Obligatorio | Qué es |
|---|---|---|
| `date` | Sí, o `from`+`to` | Un solo día, formato `YYYY-MM-DD`. |
| `from` / `to` | Sí, o `date` | Rango, **inclusive las dos fechas**. Van juntos. |
| `page` | No | Página, empieza en 1. |
| `per_page` | No | Filas por página. Default 1,000, máximo 5,000. |

Las fechas son **días naturales en hora de México** (`America/Mexico_City`): un mensaje de
las 23:30 del 17 pertenece al 17, aunque en UTC ya sea el 18.

---

## Respuesta

```json
{
  "status": "ok",
  "data": [
    { "name": "Juan Pérez", "phone": "529231311146" },
    { "name": null,         "phone": "526692522844" }
  ],
  "meta": {
    "from": "2026-08-17",
    "to": "2026-08-17",
    "total": 2,
    "page": 1,
    "per_page": 1000,
    "pages": 1
  }
}
```

- **Una fila por contacto.** A quien recibió tres mensajes ese día se le contactó una vez.
- `name` puede ser `null` si el número no tiene ficha de contacto. Se devuelve igual: se le
  contactó, y esconderlo daría un total que no cuadra.
- El teléfono va en formato mexicano `52` + 10 dígitos, **sin** el `+`.

### Paginar

Recorre de `page=1` hasta `meta.pages`. El orden es por teléfono y es estable, así que
ninguna página repite ni se salta contactos aunque entren envíos nuevos mientras se recorre.

---

## Qué cuenta como "contactado"

El mensaje **salió**: estado `sent`, `delivered` o `read`, en **cualquiera de los dos
canales** (WhatsApp y SMS).

**No** cuentan:

| Estado | Por qué |
|---|---|
| `failed` | El mensaje no salió. |
| `discarded` | El sistema lo descartó antes de enviarlo (baja, enfriamiento, tope de Meta). |
| `pending` | Todavía está en la cola. |

Es el mismo criterio con el que el panel calcula el enfriamiento y el anti-duplicado, así que
el número de este API cuadra con lo que ve el operador.

---

## Errores

Todos responden **422** con `{ "status": "error", "message": "...", "code": "..." }`.

| `code` | Cuándo |
|---|---|
| `MISSING_DATE` | No mandaste ni `date` ni `from`+`to`. Sin fecha no se responde: barrería la tabla entera. |
| `AMBIGUOUS_RANGE` | Mandaste `date` **y** `from`/`to`. No se adivina cuál querías. |
| `INVALID_RANGE` | `to` es anterior a `from`. |
| `INVALID_PARAMS` | Una fecha con formato distinto de `AAAA-MM-DD`, `from` sin `to` (o al revés), o `page`/`per_page` que no son enteros positivos. |

Y el **401** por llave faltante o inválida responde igual, con `code: UNAUTHORIZED`.

---

## Ejemplos

```bash
# Un día
curl -H "X-API-Key: LA_LLAVE" \
  "https://sender.prestamaz.site/api/contacted?date=2026-08-17"

# Un mes, de 2,000 en 2,000
curl -H "X-API-Key: LA_LLAVE" \
  "https://sender.prestamaz.site/api/contacted?from=2026-08-01&to=2026-08-31&per_page=2000&page=1"
```

---

## Si algo no cuadra

Escríbenos con tres datos y se resuelve casi siempre a la primera:

1. La **URL completa** que llamaste, sin la llave.
2. El **código HTTP** que te respondió.
3. El **cuerpo** de la respuesta.
