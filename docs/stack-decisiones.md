# Stack — Decisiones y por qué

| Decisión | Elegido | Descartados | Por qué |
|---|---|---|---|
| Backend | Laravel 10 + PHP 8.1 | Symfony (overkill), CodeIgniter (sin queues), Node (no lo domina dev) | Queue, scheduler, Eloquent `encrypted` cast, middleware, rate limiting built-in |
| Frontend S1 | Vue 3 CDN | React (build obligatorio), Blade puro (sin reactividad) | Sin npm, 3 tabs no justifican build step |
| Frontend S2+ | Vue 3 + Vite | — | Componentes `.vue`, hot reload, tree shaking |
| DB | MySQL 8 | PostgreSQL (innecesario), SQLite (no escala) | Dev lo domina, soporte Laravel primera clase, suficiente para 200K contactos |
| Tests BD | MySQL (`wa_cloud_panel_test`) | SQLite :memory: (diferencias de comportamiento con MySQL en prod) | Mismo motor dev=test=prod, XAMPP ya corre MySQL |
| Queue S1 | `database` driver | Redis (necesita instalación extra) | Zero setup, suficiente para desarrollo |
| Queue S2 | Redis + Horizon | — | RAM-based para 200K/mes, Horizon para monitoreo |
| Hosting | VPS Ubuntu 22.04 + Nginx | cPanel (no soporta queues, Redis, Supervisor) | Obligatorio para `queue:work` persistente |
| VPS | Hetzner CX22 ~$5/mes | DigitalOcean ($6), Vultr ($5) | Mejor relación precio/specs |
| API WA | Cloud API directa | Twilio ($$$), MessageBird ($$) | Sin intermediarios, control total, PMP directo |

## Por qué NO cPanel

cPanel no permite:
- Procesos persistentes (`queue:work` + Supervisor)
- Redis sin addon pago
- Configuración de Nginx (solo Apache)
- Cron con frecuencia menor a 1 minuto

Para queues de WhatsApp masivas esto es bloqueante. VPS propio es el único camino viable.

## Por qué WhatsApp Cloud API directa (no intermediarios)

- Twilio cobra markup encima del PMP de Meta (~2-3x más caro)
- MessageBird igual
- Con Cloud API directa: `~$0.04/msg Marketing` × 200K = $8,000 USD/mes sin margen de intermediario
- Control total sobre webhooks, delivery receipts, plantillas
- Sin vendor lock-in
