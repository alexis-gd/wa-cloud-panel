# Convenciones de API

## Rutas

- Prefijo: `/api/` para todos los endpoints.
- Verbos REST estándar: GET (leer), POST (crear/ejecutar), PUT (actualizar), DELETE (eliminar).
- Nombres en plural, snake_case: `/api/campaigns`, `/api/message_logs`.
- Acciones especiales como sub-recurso: `POST /api/campaigns/{id}/execute`.

## Responses JSON

Formato estándar para TODAS las respuestas:

```json
// Éxito
{ "status": "ok", "data": { ... } }

// Éxito con paginación
{ "status": "ok", "data": [...], "meta": { "total": 500, "page": 1, "per_page": 20 } }

// Error
{ "status": "error", "message": "Descripción legible", "code": "INVALID_TEMPLATE" }
```

## Middleware

- `ApiKeyMiddleware` en TODAS las rutas `/api/*` excepto `/api/health` y `/webhook`.
- `throttle:60,1` en todas las rutas API.
- Validación de request con Form Requests de Laravel, no validación manual en controllers.

## Controllers

- Un controller por recurso: `CampaignController`, `ContactController`, etc.
- Máximo 5 métodos por controller: `index`, `store`, `show`, `update`, `destroy`.
- Acciones especiales van en controllers dedicados: `CampaignExecutionController@store`.
- Siempre retornar `response()->json()`, nunca `echo` ni `return view()` en rutas API.

## Códigos HTTP

- 200: Operación exitosa
- 201: Recurso creado
- 400: Request mal formado
- 401: No autenticado (falta X-API-Key)
- 404: Recurso no encontrado
- 422: Datos no procesables (ej: plantilla no aprobada)
- 429: Rate limit alcanzado
- 500: Error interno (logueado, nunca exponer stack trace)
