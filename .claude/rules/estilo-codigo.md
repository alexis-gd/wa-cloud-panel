# Reglas de estilo de código

## PHP / Laravel

- PSR-12 estricto. Clases en PascalCase, métodos en camelCase, variables en snake_case.
- Tipado fuerte: declarar tipos en parámetros y retornos de todos los métodos.
- No usar `dd()` o `dump()` en código mergeado — solo `Log::info()` / `Log::error()`.
- Modelos: siempre definir `$fillable`, nunca usar `$guarded = []`.
- Migraciones: una tabla por migración, nombres descriptivos (`create_campaigns_table`).
- Sin lógica de negocio en controllers — delegarla a Services.
- Inyección de dependencias vía constructor, no `app()` directo.

## Vue.js

- Composition API (`setup`) para componentes nuevos. No Options API.
- Stage 1 (CDN): todo en `public/assets/js/app.js`, funciones separadas por comentarios claros.
- Stage 2+ (Vite): componentes separados en `.vue`, una responsabilidad por componente.
- Variables reactivas con `ref()` y `reactive()`, no asignación directa.
- Llamadas API centralizadas en un objeto `api` o módulo, no `fetch()` suelto en componentes.

## General

- Commits en español, imperativos: "Agrega job de envío", "Corrige validación de webhook".
- No dejar código comentado — si no se usa, se borra.
- Máximo 200 líneas por archivo. Si se pasa, refactorizar en clases/componentes.
