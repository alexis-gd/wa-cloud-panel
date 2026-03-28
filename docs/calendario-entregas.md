# Calendario de entregas

---

## Sección 1: Entregas al cliente (lo que él ve y toca)

Estas son las demos visuales. El cliente no necesita saber los detalles técnicos.

### Entrega 1 — Panel en producción + gestión de contactos

- Cliente entra a URL real con login (su marca)
- Sube Excel de contactos
- Ve dashboard con estadísticas de contactos cargados
- Todo en HTTPS con SSL
- **Momento wow**: "Ya tengo mi sistema en línea, con mi login, ya subí mis contactos"
- *Nota interna: el VPS queda montado desde aquí — nos ahorra estrés después*

### Entrega 2 — Demo WhatsApp en vivo

- Selecciona plantilla aprobada
- Le pedimos SU número de celular en la demo
- Le damos "enviar" → le llega el WhatsApp a su celular en ese momento
- Ve en el panel que se envió y entregó
- **Momento wow**: "Me llegó al celular, es real, esto funciona"
- *Nota interna: demo controlada. Business Manager, System User y plantillas aprobadas listas antes*

### Entrega 3 — Métricas + inteligencia del sistema

- Dashboard con gráficas envío por día/hora
- Semáforo verde/amarillo/rojo calidad del número
- Lista negra automática cuando alguien pide baja
- Reportes descargables Excel
- **Momento wow**: "El sistema me cuida solo, bloquea a los que no quieren"

### Entrega 4 — Software completo + capacitación

- Campañas masivas reales enviándose
- Multi-número con balanceo
- Respuestas entrantes en el panel
- Guía impresa de operador
- Sesión de capacitación con equipo + video grabado
- **Momento wow**: "Mi equipo ya sabe usarlo, esto está en producción"

---

## Sección 2: Plan de desarrollo (interno, no compartir con cliente)

### Etapa 1 (alimenta Entregas 1 y 2)

- [ ] Deploy básico VPS: Ubuntu + Nginx + PHP-FPM + SSL + MySQL
- [ ] Auth Laravel (login/registro)
- [x] Upload Excel (PhpSpreadsheet) — parcialmente hecho
- [x] ContactController: index, stats, upload, optOut
- [ ] Dashboard con conteos básicos
- [ ] Job `SendWhatsAppMessage` con queue database
- [ ] CampaignController: crear, listar, ejecutar
- [ ] Scheduler horario legal L-V 7AM-10PM CST
- [ ] Warm-up limits automáticos por tier
- [ ] Tests Feature: health, templates, webhook, contacts, campaigns
- [ ] ⚠️ Paralelo: crear/aprobar plantillas Marketing en Meta (1-48 hrs)
- [ ] ⚠️ Paralelo: configurar Business Manager + System User token permanente

### Etapa 2 (alimenta Entrega 3)

- [ ] Dashboard métricas tiempo real (enviados, entregados, leídos, fallidos)
- [ ] Gráficas envío por día/hora
- [ ] Opt-out automático STOP/NO/BAJA
- [ ] Feedback visual calidad número (semáforo)
- [ ] Lista negra automática + manual
- [ ] Circuit breaker por calidad
- [ ] Export reportes CSV/Excel
- [ ] Tests integración completos
- [ ] 📘 Guía de operador (capturas paso a paso)

### Etapa 3 (alimenta Entrega 4)

- [ ] Redis + Laravel Horizon
- [ ] Multi-número con balanceo inteligente
- [ ] Vue migrado a Vite (componentes `.vue`)
- [ ] Inbound messages (chat entrante)
- [ ] Multi-agente de atención
- [ ] Tags y segmentación de contactos
- [ ] Deploy script automatizado
- [ ] Monitoreo + alertas
- [ ] Tests regresión automatizados
- [ ] ⚠️ Warm-up número producción (3-4 semanas paralelas)
- [ ] 📘 Guía completa: operador + administrador
- [ ] 📘 Sesión capacitación + video grabado
