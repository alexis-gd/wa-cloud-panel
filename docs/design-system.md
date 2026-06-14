# Prestamaz Panel — Design System

Panel de envío masivo WhatsApp + SMS. Stack: Vue 3 + PrimeVue v4 (tema Aura personalizado). Sin Tailwind — CSS scoped con variables PrimeVue.

---

## 1. Colores

### Paleta principal — Emerald (primary override de Aura)

| Token | Hex | Uso |
|---|---|---|
| `--p-primary-50` | `#ecfdf5` | Fondos muy sutiles |
| `--p-primary-100` | `#d1fae5` | Badge "actual" fondo |
| `--p-primary-400` | `#34d399` | — |
| `--p-primary-500` | `#10b981` | Brand color principal, iconos de sección, nav activo |
| `--p-primary-600` | `#059669` | Texto sobre fondo claro, pct-ok |
| `--p-primary-700` | `#047857` | Badge "actual" texto |
| `--p-primary-900` | `#064e3b` | — |

### Colores del layout (hardcoded, no PrimeVue)

| Nombre | Hex | Uso |
|---|---|---|
| Navy dark | `#0f172a` | Sidebar background, page title, login title, loading screen |
| Background app | `#f1f5f9` | Fondo general de la app, login page |
| White | `#ffffff` | Cards, topbar |
| Border | `#e2e8f0` | Topbar border, separadores |
| Sidebar border | `rgba(255,255,255,0.08)` | Separadores dentro del sidebar dark |
| Sidebar nav inactive | `#94a3b8` | Nav items sin hover |
| Sidebar nav hover text | `#e2e8f0` | Nav items hover |
| Sidebar nav hover bg | `rgba(255,255,255,0.07)` | Nav items hover bg |
| Sidebar nav active bg | `rgba(16,185,129,0.15)` | Nav item activo bg (emerald 15%) |
| Nav active text | `#10b981` | Nav item activo texto |
| Label dark | `#374151` | Labels de formularios |
| Text secondary | `#64748b` | Logout btn, versión |
| Version | `#334155` | Versión en sidebar footer |

### Colores semánticos PrimeVue (usados via var())

| Variable | Uso en el sistema |
|---|---|
| `var(--p-text-color)` | Texto principal |
| `var(--p-text-muted-color)` | Labels, subtítulos, metadatos |
| `var(--p-surface-50)` | Fila resaltada tabla (current month) |
| `var(--p-surface-100)` | Bordes de filas tabla |
| `var(--p-surface-200)` | Track de barras de progreso, bordes de tabla headers |
| `var(--p-green-500)` | Stat "Entregados", stat "Activos", dot calidad GREEN |
| `var(--p-green-600)` | pct-done, texto calidad GREEN, "¡Capacidad 100%!" |
| `var(--p-red-500)` | Stat "Fallidos", dot calidad RED, health error |
| `var(--p-red-600)` | Texto calidad RED, login error |
| `var(--p-yellow-500)` | Dot calidad YELLOW, barra pct-warn |
| `var(--p-yellow-700)` | Texto calidad YELLOW, texto pct-warn |
| `var(--p-orange-500)` | Stat "Opt-out" |
| `var(--p-primary-color)` | Stat "Leídos" |

### Colores de barras de progreso (pct classes)

| Estado | Rango | Color |
|---|---|---|
| `pct-done` | 100%+ | `var(--p-green-500)` / `var(--p-green-600)` |
| `pct-ok` | 60–99% | `var(--p-primary-500)` / `var(--p-primary-600)` |
| `pct-warn` | 30–59% | `var(--p-yellow-500)` / `var(--p-yellow-700)` |
| `pct-low` | 0–29% | `var(--p-red-400)` / `var(--p-red-500)` |

### Colores de gráficas (Chart.js bar)

| Serie | Color |
|---|---|
| Enviados (en tránsito) | `rgba(99, 102, 241, 0.7)` — indigo |
| Entregados | `rgba(34, 197, 94, 0.7)` — green |
| Leídos | `rgba(14, 165, 233, 0.7)` — sky blue |
| Fallidos | `rgba(239, 68, 68, 0.7)` — red |

---

## 2. Tipografía

Sistema base: fuente del tema PrimeVue Aura (Inter / sistema sans-serif). No se declara `font-family` custom.

### Escala de tamaños usada

| Tamaño rem | px aprox | Uso |
|---|---|---|
| `0.68rem` | 10.9px | Versión sidebar, role-tag, current-badge |
| `0.70rem` | 11.2px | stat-info icono tooltip |
| `0.75rem` | 12px | Labels muted (health, monthly, stat-lbl), table headers |
| `0.78rem` | 12.5px | section-subtitle, monthly-footer, login-error |
| `0.82rem` | 13.1px | nav-item, health-error, logs-pagination, logs-total |
| `0.85rem` | 13.6px | history-table, field labels login, chart-loading, empty-msg |
| `0.90rem` | 14.4px | nav-item, login-error icon |
| `0.95rem` | 15.2px | health-value, monthly-month |
| `1.00rem` | 16px | page-title, nav-brand, sidebar-brand font-size |
| `1.10rem` | 17.6px | login-brand |
| `1.20rem` | 19.2px | login-title |
| `1.40rem` | 22.4px | monthly-sent (número grande) |
| `1.60rem` | 25.6px | login-brand icon |
| `2.00rem` | 32px | stat-num (KPIs grandes), loading spinner |

### Pesos

| Peso | Uso |
|---|---|
| 500 | month-cell, nav hover highlight |
| 600 | user-name, page-title, nav activo, section-title, health-value, field labels, monthly-month |
| 700 | sidebar-brand, login-brand, login-title, stat-num, monthly-sent, monthly-pct, stat pct colores |

---

## 3. Espaciado

Sin sistema de 4px exacto — el sistema usa estos valores recurrentes:

| Valor | Uso |
|---|---|
| 2px | gap nav-items, gap label-row, gap health-dot, gap stat-label-row |
| 4px | gap paginator, gap chart-colors, padding badge |
| 6px | gap field, gap sidebar-footer, gap health-label, margin-top monthly-footer |
| 7px | padding table rows, gap section-header |
| 8px | gap user-info, gap login-brand, gap nav-item, padding table rows, margin logs-filter |
| 10px | padding monthly-bar-track, gap sidebar-brand, height barra principal |
| 12px | padding nav-item, gap logs-page-info |
| 14px | padding sidebar-footer |
| 16px | gap stats-row, margin-bottom cards (.mb), padding login-card lateral, gap login-form, sidebar-brand padding lateral |
| 18px | padding sidebar-brand |
| 20px | padding sidebar-brand top, padding topbar |
| 24px | padding content (desktop), margin login-card campos |
| 28px | margin-bottom login-brand |
| 32px | gap health-row |
| 36px | padding login-card lateral |
| 40px | padding login-card top/bottom |

---

## 4. Borders & Shadows

| Elemento | Valor |
|---|---|
| Border radius — login card | 16px |
| Border radius — barra progreso | 6px |
| Border radius — mini barra | 4px |
| Border radius — current-badge | 4px |
| Border radius — login error | 8px |
| Border radius — nav-item | 8px |
| Box shadow — login card | `0 4px 24px rgba(0,0,0,0.08)` |
| Box shadow — Cards PrimeVue | heredado del tema Aura |
| Border topbar | `1px solid #e2e8f0` |
| Border sidebar separators | `1px solid rgba(255,255,255,0.08)` |

---

## 5. Layout

### Shell principal

```
┌─────────────────────────────────────────────────────┐
│  Sidebar (220px, fixed, #0f172a)                    │
│  ┌────────────────────────────────────────────────┐ │
│  │ Brand (emerald + icon)                         │ │
│  │                                                 │ │
│  │ Nav items (RouterLinks)                        │ │
│  │   • Dashboard      pi-home                     │ │
│  │   • Contactos      pi-users                    │ │
│  │   • Campañas       pi-send                     │ │
│  │   • Conversaciones pi-comments                 │ │
│  │   • Plantillas     pi-file-edit  (admin+)      │ │
│  │   • Usuarios       pi-user-edit  (admin+)      │ │
│  │   • Configuración  pi-cog        (superadmin)  │ │
│  │                                                 │ │
│  │ Footer: user + role tag + logout + version     │ │
│  └────────────────────────────────────────────────┘ │
│                                                      │
│  Main (margin-left: 220px)                          │
│  ┌────────────────────────────────────────────────┐ │
│  │ Topbar (56px, sticky, white, border-bottom)    │ │
│  │   menu-btn (mobile) | page-title | HelpPopover │ │
│  ├────────────────────────────────────────────────┤ │
│  │ Content (padding: 24px)                        │ │
│  │   <RouterView />                               │ │
│  └────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────┘
```

**Mobile (≤768px):** Sidebar oculto, slide-in con `transform: translateX`, overlay oscuro `rgba(0,0,0,0.45)`, menu-btn hamburguesa visible.

### Content padding
- Desktop: `24px`
- Mobile: `16px`

---

## 6. Rutas y páginas

| Ruta | Vista | Acceso |
|---|---|---|
| `/login` | LoginView | público (sin layout) |
| `/` | DashboardView | todos los roles |
| `/contacts` | ContactsView | todos los roles |
| `/campaigns` | CampaignsView | todos los roles |
| `/conversations` | ConversationsView | todos los roles |
| `/templates` | TemplatesView | admin + superadmin |
| `/users` | UsersView | admin + superadmin |
| `/settings` | SettingsView | solo superadmin |

---

## 7. Roles y etiquetas

| Rol | Label UI | PrimeVue severity (Tag) |
|---|---|---|
| `superadmin` | Super Admin | `warn` (amber) |
| `admin` | Admin | `danger` (red) |
| `operator` | Operador | `info` (blue) |
| `agent` | Agente | `secondary` (gray) |

---

## 8. Estados de mensajes

| Status | Label ES | PrimeVue Tag severity | Stat color |
|---|---|---|---|
| `pending` | Pendiente | `warn` | — |
| `sent` | En tránsito | `info` | default |
| `delivered` | Entregado | `success` | `var(--p-green-500)` |
| `read` | Leído | `secondary` | `var(--p-primary-color)` |
| `failed` | Fallido | `danger` | `var(--p-red-500)` |

---

## 9. Componentes PrimeVue en uso

| Componente | Import | Uso principal |
|---|---|---|
| `Button` | `primevue/button` | Todas las acciones |
| `Card` | `primevue/card` | Contenedores de sección |
| `Tag` | `primevue/tag` | Status badges, role tags |
| `Toast` | `primevue/toast` | Notificaciones (bottom-right) |
| `DataTable` | `primevue/datatable` | Tablas de datos |
| `Column` | `primevue/column` | Columnas de DataTable |
| `Select` | `primevue/select` | Dropdowns/selects |
| `InputText` | `primevue/inputtext` | Inputs de texto |
| `Password` | `primevue/password` | Input de contraseña (con toggleMask) |
| `Chart` | `primevue/chart` | Gráficas (Chart.js bajo el capó) |
| `Tooltip` | `primevue/tooltip` | Directiva v-tooltip |

### Convención de Button

| Variante | Props | Cuándo |
|---|---|---|
| Primario | (ninguna) | Acción principal de página |
| Secundario | `severity="secondary"` | Acción secundaria (sync, export) |
| Cancelar dialog | `text` | Cancel en dialogs |
| Icono acción | `icon="pi pi-*" text size="small"` | Refresh, download en cards |
| Destructivo inline | `text severity="danger" size="small"` | Borrar fila |

**Nunca usar `outlined`.**

---

## 10. Patrones de UI recurrentes

### Section header
```
[icon emerald] [Título bold] [— subtítulo muted]
```
Precede a un grupo de stat-cards. Margin-bottom: 6px antes de las cards.

### Card title row
```html
<div class="card-title-row">  <!-- flex justify-between -->
  <span>Título</span>
  <div class="title-actions">  <!-- flex gap-2 -->
    <Button icon refresh />
    <Button icon download />
  </div>
</div>
```

### Stats row (KPIs)
```
[Card] [Card] [Card] [Card]   ← grid 4 columnas, gap 16px
                               ← 2 columnas en ≤900px
  Cada card:
    label muted (0.75rem)
    KPI number (2rem, bold, color semántico)
```

### Health row (status bar)
```
[label + valor] [label + valor] ... [refresh btn]
flex gap-32, wrap
```

### Progress bar
```
track: surface-200, h:10px, radius:6px
fill: color pct-*, h:100%, radius:6px, transition width
footer: muted meta / remaining
```

### Mini bar (tabla histórico)
```
track: surface-200, h:7px, radius:4px
fill: color pct-*, h:100%, radius:4px
```

### Nav item
```
[icon 18px] [label]
padding: 10px 12px, radius:8px, font-size:.9rem
default: color #94a3b8
hover: bg rgba(255,255,255,.07), color #e2e8f0
active: bg rgba(16,185,129,.15), color #10b981, font-weight:600
```

---

## 11. Pantalla de Login

Estructura:
```
página: min-h 100vh, bg #f1f5f9, centrado
  card: bg white, radius 16px, padding 40px 36px, max-w 400px, shadow
    brand: panel dividido — navy izquierda (45%) + form blanca derecha (55%)
    wordmark: [Prestamaz] (emerald) [Panel] (blanco) + pill WhatsApp·SMS
    h2: Inicia sesión — #0f172a 1.7rem bold
    form:
      field: label + IconField>InputText (email con pi-envelope)
      field: label + Password (toggle-mask, sin feedback)
      [error inline: bg #fef2f2, color #dc2626, radius 8px]
      Button fluid primary (Entrar)
```

---

## 12. Loading screen (app init)

```
min-h: 100vh
bg: #0f172a  ← mismo navy del sidebar
center: pi-spin pi-spinner, 2rem, color #10b981
```

---

## 13. Iconografía

PrimeIcons (`pi pi-*`). Iconos usados:

| Icono | Contexto |
|---|---|
| `pi-whatsapp` | Brand (sidebar, login) |
| `pi-home` | Dashboard nav |
| `pi-users` | Contactos nav, sección contactos |
| `pi-send` | Campañas nav, sección mensajes |
| `pi-comments` | Conversaciones nav |
| `pi-file-edit` | Plantillas nav |
| `pi-user-edit` | Usuarios nav |
| `pi-cog` | Configuración nav |
| `pi-sign-out` | Logout btn |
| `pi-bars` | Hamburger menu (mobile) |
| `pi-refresh` | Refresh en cards |
| `pi-download` | Export/download |
| `pi-info-circle` | Tooltips de métricas |
| `pi-spin pi-spinner` | Loading states |
| `pi-exclamation-circle` | Error inline |
| `pi-chevron-left/right` | Paginación |

---

## 14. Identidad del sistema

- **Nombre**: Prestamaz Panel
- **Cliente**: Prestamaz (empresa de préstamos, Mazatlán, Sinaloa)
- **Color de marca**: Emerald `#10b981`
- **Color de estructura**: Navy `#0f172a`
- **Sensación**: profesional, confiable, oscuro+claro, sin saturar el color
- **Idioma UI**: español (México)
- **Dominio**: envío masivo WhatsApp + SMS para marketing financiero
