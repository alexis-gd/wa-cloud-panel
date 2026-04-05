# Plan: Detección de contactos inalcanzables (`unreachable`)

**Objetivo:** proteger el ratio `delivered/sent` del número de WhatsApp marcando automáticamente
contactos que consistentemente no reciben mensajes, sin confundirlos con casos temporales
(sin internet, teléfono apagado, vacaciones).

---

## Contexto del problema

Cuando un contacto bloquea el número, WhatsApp acepta el envío (`sent`) pero nunca lo entrega.
El sistema no recibe error — el log queda en `sent` para siempre. Acumular estos contactos
en campañas degrada el ratio `delivered/sent` y baja la calidad del número en Meta.

**Señal definitiva:** WhatsApp expira mensajes no entregados a los **30 días**.
Un mensaje con 30+ días en `sent` jamás llegará. Eso elimina falsos positivos
(sin internet temporal, teléfono apagado, viaje).

---

## Nuevo status: `unreachable`

| Status | Causa | Reversible | Se excluye de campañas |
|---|---|---|---|
| `active` | Normal | — | No |
| `opted_out` | Respondió STOP/NO/BAJA/CANCELAR | No | Sí |
| `invalid` | Meta devolvió error `131026` | No | Sí |
| **`unreachable`** | **2+ mensajes viejos nunca entregados** | **Sí (admin manual)** | **Sí** |

`unreachable` es el único status reversible — el admin puede reactivar el contacto
si hay evidencia de que volvió a ser alcanzable (por ejemplo, si el contacto escribió).

---

## Regla de detección

```
Marcar contacto como unreachable cuando:
  - status = 'active'
  - tiene 2 o más message_log con status = 'sent'
  - el más antiguo de esos logs tiene sent_at < NOW() - 30 días (en UTC)
  - NO tiene ningún message_log con status IN ('delivered', 'read') en toda su historia
```

La condición de "nunca delivered/read en toda su historia" evita marcar contactos
que en el pasado recibieron mensajes bien pero ahora tienen 2 pendientes (podría ser
un período de baja conectividad).

---

## Componentes a implementar

### 1. Migración — agregar `unreachable` al enum de `contacts.status`

```php
// Archivo: database/migrations/XXXX_add_unreachable_to_contacts_status.php
$table->enum('status', ['active', 'opted_out', 'invalid', 'unreachable'])
      ->default('active')->change();
```

### 2. Comando artisan `wa:mark-unreachable`

**Archivo:** `app/Console/Commands/MarkUnreachableContactsCommand.php`

```
- Query contacts con status = 'active'
- Join message_log: COUNT(*) WHERE status = 'sent' → al menos 2
- JOIN message_log: MIN(sent_at) WHERE status = 'sent' → 30+ días en UTC
- Subquery: COUNT(*) WHERE status IN ('delivered','read') = 0
- UPDATE contacts SET status = 'unreachable' WHERE id IN (...)
- Log: "X contactos marcados como unreachable"
```

**Horario:** `6:00 AM America/Mexico_City` (antes de que abra la ventana de envíos a las 9AM).
Las campañas aún no han arrancado → sin contención de BD.

```php
// app/Console/Kernel.php
$schedule->command('wa:mark-unreachable')
         ->dailyAt('06:00')
         ->timezone('America/Mexico_City')
         ->withoutOverlapping();
```

### 3. `SendWhatsAppMessageJob` — agregar check

```php
// Junto a los checks de opted_out e invalid
if ($contact->status === 'unreachable') {
    $this->createLog($contact, $phone, 'discarded', 'unreachable');
    return;
}
```

### 4. Dashboard — widget "Base de contactos"

Agregar `unreachable` como nuevo stat en la tarjeta de contactos:

```php
// DashboardController::stats()
'unreachable' => (int) ($contactTotals['unreachable'] ?? 0),
```

```vue
// DashboardView.vue — contactStats array
{ key: 'unreachable', label: 'Inalcanzables', class: 'unreachable',
  tooltip: 'Contactos que recibieron 2+ mensajes en los últimos 30 días sin que ninguno se entregara...' }
```

### 5. ContactController — reactivación manual

```
PUT /api/contacts/{id}  →  { status: 'active' }
```

Solo admin puede reactivar un contacto `unreachable`.
Agregar validación en el Form Request para permitir este cambio de status.

---

## Tests requeridos

| Test | Tipo |
|---|---|
| Comando marca contacto con 2 sent viejos y sin delivered | Feature |
| Comando NO marca si el sent más antiguo tiene < 30 días | Feature |
| Comando NO marca si tiene algún delivered en su historia | Feature |
| Comando NO marca contactos opted_out o invalid | Feature |
| Job descarta contacto unreachable con log 'discarded' | Feature |
| Dashboard devuelve conteo de unreachable | Feature |

---

## Lo que NO hace este feature

- No elimina contactos de la BD (igual que opted_out, se conservan para auditoría)
- No marca contactos que Meta devolvió `131026` (esos ya los maneja el job como `invalid`)
- No corre durante la campaña — el job de envío solo verifica el status, no lo calcula
- No es retroactivo agresivo — solo marca con evidencia de 30+ días acumulados

---

## Dependencias

- Migración de enum en `contacts.status`
- Índice existente en `message_log` (contact_id, status) ya cubre la query
- Scheduler de Laravel (ya configurado para horario de envíos)

---

## Referencia

Este plan responde al backlog en `docs/calendario-entregas.md`.
Decisión de diseño registrada en `docs/arquitectura-referencia.md`.
