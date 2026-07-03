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

## Componentes PrimeVue — convenciones de uso

Mantener consistencia entre todas las vistas. No variar estilos de componentes sin razón explícita.

### Button
| Uso | Props |
|---|---|
| Acción principal de página (crear, guardar) | sin props extra — color primary por defecto |
| Acción secundaria de página (sincronizar, exportar) | `severity="secondary"` |
| Cancelar en dialogs | `text` (sin `severity`) |
| Confirmar en dialogs | sin props extra |
| Acciones destructivas inline (borrar fila) | `text severity="danger" size="small"` |
| Acciones de icono sin texto | `icon="pi pi-*" text size="small"` |

- **Nunca usar `outlined`** salvo que el diseño lo requiera explícitamente.
- **Nunca mezclar** `outlined` y sin-outlined en la misma fila de acciones.
- Botón "Cancelar" en dialogs: siempre `text`, sin `severity`.

## CSS / Estilos

- **Tailwind NO está instalado** — no usar clases utilitarias de Tailwind (`flex`, `w-72`, `gap-6`, `h-[calc...]`, etc.).
- Todo el CSS va en `<style scoped>` dentro del `.vue`, con clases semánticas propias (`.contacts`, `.stats-row`, etc.).
- Variables de diseño: usar las de PrimeVue (`var(--p-primary-500)`, `var(--p-surface-100)`, `var(--p-text-muted-color)`, etc.) — nunca colores hardcodeados.
- Si el proyecto adopta Tailwind en el futuro, se actualiza esta regla y se instala explícitamente.

## Fechas y timezones

- Los timestamps (`sent_at`, `created_at`, etc.) se guardan en UTC en BD — esto NO cambia.
- **Al devolver timestamps al frontend** (en resources, controllers o responses), siempre convertir a `America/Mexico_City`:
  ```php
  $model->sent_at->setTimezone('America/Mexico_City')->format('Y-m-d H:i')
  ```
- **Nunca devolver un ISO UTC crudo** si el dato se va a mostrar como hora al operador.
- El frontend nunca hace conversión de zona — recibe la hora ya en CST.
- Para filtros de fecha en queries, nunca `today()` ni `whereDate()` con UTC — usar:
  ```php
  whereBetween('sent_at', [
      now('America/Mexico_City')->startOfDay()->utc(),
      now('America/Mexico_City')->endOfDay()->utc(),
  ])
  ```

## Guion largo prohibido (regla dura)

- **Nunca usar el guion largo `—` (em dash).** En su lugar usar guion normal `-`.
- Aplica a TODO: copys de UI, textos visibles al operador, comentarios de código, docs, mensajes de commit, descripciones de PR, tooltips, help popovers.
- Vale igual para el guion medio `–` (en dash): usar `-`.
- Al tocar un archivo con `—` existente, reemplazarlo por `-` de paso.

## General

- Commits en español, imperativos: "Agrega job de envío", "Corrige validación de webhook".
- No dejar código comentado - si no se usa, se borra.
- Máximo 200 líneas por archivo. Si se pasa, refactorizar en clases/componentes.
