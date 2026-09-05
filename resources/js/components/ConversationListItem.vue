<template>
    <div
        @click="$emit('select', contact)"
        :class="['sidebar-item',
                 active ? 'sidebar-item--active' : '',
                 esMia ? 'sidebar-item--mine' : '']"
    >
        <div class="item-row">
            <span class="item-name">
                <span class="state-dot" :class="'dot--' + ciclo" v-tooltip.top="cicloTag.label"></span>
                <span class="item-name-text">{{ contact.name || contact.phone }}</span>
            </span>
            <span class="item-time">{{ hora }}</span>
        </div>
        <div class="item-row">
            <span class="item-preview">{{ contact.last_message }}</span>
            <span class="item-badges">
                <Tag :value="cicloTag.label" :severity="cicloTag.severity" class="item-tag" />
                <span class="assign-mini" :class="'assign--' + asignacion.cls" v-tooltip.top="asignacion.title">
                    {{ asignacion.label }}
                </span>
            </span>
        </div>
    </div>
</template>

<script setup>
/**
 * Una fila de la lista de conversaciones.
 *
 * Por qué es un componente y no un `v-for` con funciones sueltas: antes cada fila llamaba a
 * seis funciones desde la plantilla, y una plantilla vuelve a ejecutarlas en CADA render del
 * componente padre. Con 938 conversaciones eso eran miles de llamadas por tecleo, y la peor
 * era `formatTime`, que armaba un formateador de fechas nuevo por fila.
 *
 * Como componente, Vue solo lo vuelve a dibujar si cambian SUS props, y los `computed` se
 * quedan en caché mientras el contacto no cambie.
 */
import { computed } from 'vue';
import Tag from 'primevue/tag';

const props = defineProps({
    contact      : { type: Object, required: true },
    active       : { type: Boolean, default: false },
    currentUserId: { type: [Number, null], default: null },
});

defineEmits(['select']);

// Prioridad: Baja (terminal) > Pospuesto > Cerrada (ventana 24h) > Abierta.
const LIFECYCLE = {
    abierta: { label: 'Abierta',   severity: 'success'   },
    cerrada: { label: 'Cerrada',   severity: 'secondary' },
    snooze:  { label: 'Pospuesto', severity: 'warn'      },
    baja:    { label: 'Baja',      severity: 'danger'    },
};

// Un solo formateador para toda la lista. Crear uno por fila es de lo más caro que hay en
// JS: `toLocaleTimeString` arma un Intl.DateTimeFormat cada vez que se le llama.
const HORA = new Intl.DateTimeFormat('es-MX', { hour: '2-digit', minute: '2-digit' });

const ciclo = computed(() => {
    const c = props.contact;
    if (c.status === 'opted_out') return 'baja';
    if (c.snoozed_until)          return 'snooze';
    if (! c.window_open)          return 'cerrada';
    return 'abierta';
});

const cicloTag = computed(() => LIFECYCLE[ciclo.value]);

const esMia = computed(() =>
    props.contact.assigned_to?.id === props.currentUserId
);

const asignacion = computed(() => {
    const asignado = props.contact.assigned_to;

    if (! asignado) {
        return { label: 'Sin asignar', cls: 'unassigned', title: 'Nadie la atiende' };
    }
    if (asignado.id === props.currentUserId) {
        return { label: 'Tú', cls: 'mine', title: 'Asignada a ti' };
    }
    return { label: iniciales(asignado.name), cls: 'other', title: `Asignada a ${asignado.name}` };
});

const hora = computed(() => {
    const iso = props.contact.last_message_at;
    if (! iso) return '';
    return HORA.format(new Date(iso));
});

function iniciales(nombre) {
    return (nombre || '?').trim().split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase();
}
</script>

<style scoped>
.sidebar-item {
  padding: 10px 14px;
  border-bottom: 1px solid var(--p-surface-100);
  cursor: pointer;
  transition: background .12s;
}
.sidebar-item--mine         { border-left: 3px solid var(--p-green-500); }
.sidebar-item:hover         { background: var(--p-surface-50); }
/* Seleccionada = fondo tintado (sin borde de color, para no confundir con el verde de "mia") */
.sidebar-item--active       { background: var(--p-primary-100); }
.sidebar-item--mine.sidebar-item--active { background: var(--p-primary-100); }

.item-row    { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 3px; }
.item-name   { font-size: .85rem; font-weight: 600; display: flex; align-items: center; gap: 6px; min-width: 0; flex: 1; }
.item-name-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.item-time   { font-size: .7rem; color: var(--p-text-muted-color); flex-shrink: 0; }
.item-preview{ font-size: .75rem; color: var(--p-text-muted-color); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }
.item-badges { display: flex; align-items: center; gap: 5px; flex-shrink: 0; }
.item-tag    { flex-shrink: 0; font-size: .65rem !important; }

/* Punto de estado (ciclo de vida) junto al nombre */
.state-dot   { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.dot--abierta { background: var(--p-green-500); }
.dot--cerrada { background: var(--p-surface-400); }
.dot--snooze  { background: var(--p-amber-500); }
.dot--baja    { background: var(--p-red-500); }

/* Mini indicador de asignacion (separado del estado) */
.assign-mini {
  font-size: .6rem; font-weight: 700; line-height: 1;
  padding: 3px 5px; border-radius: 5px; flex-shrink: 0; white-space: nowrap;
}
.assign--unassigned { background: var(--p-amber-100); color: var(--p-amber-700); }
.assign--mine       { background: var(--p-green-100); color: var(--p-green-700); }
.assign--other      { background: var(--p-surface-200); color: var(--p-text-muted-color); }
</style>
