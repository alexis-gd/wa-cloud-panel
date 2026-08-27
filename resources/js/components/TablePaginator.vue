<template>
    <div class="table-paginator" v-if="total !== null">
        <div class="pager-size">
            <span class="pager-lbl">Mostrar</span>
            <Select
                :model-value="perPage"
                :options="sizeOptions"
                option-label="label"
                option-value="value"
                size="small"
                style="width: 110px"
                @update:model-value="onSizeChange"
            />
        </div>

        <div class="pager-nav">
            <Button
                icon="pi pi-chevron-left"
                text
                severity="secondary"
                size="small"
                :disabled="page <= 1"
                @click="$emit('update:page', page - 1)"
            />
            <span class="pager-info">Página {{ page }} de {{ Math.max(1, totalPages) }}</span>
            <Button
                icon="pi pi-chevron-right"
                text
                severity="secondary"
                size="small"
                :disabled="page >= totalPages"
                @click="$emit('update:page', page + 1)"
            />
        </div>

        <span class="pager-total">{{ formattedTotal }} {{ itemLabel }}</span>

        <!-- Aviso de recorte: "Todos" tiene tope duro para no tumbar el navegador -->
        <span v-if="capped" class="pager-cap">
            <i class="pi pi-exclamation-triangle"></i>
            Mostrando {{ formatNumber(capLimit) }} de {{ formattedTotal }} - afina el filtro o usa Exportar
        </span>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import Button from 'primevue/button';
import Select from 'primevue/select';

const props = defineProps({
    /** Página actual (1-based). */
    page       : { type: Number, required: true },
    /** Total de páginas según el tamaño elegido. */
    totalPages : { type: Number, required: true },
    /** Total de registros que devuelve el filtro (antes del tope de "Todos"). */
    total      : { type: Number, default: null },
    /** Tamaño de página actual: un número o 'all'. */
    perPage    : { type: [Number, String], required: true },
    /** Cómo se llaman los registros en el conteo: "contactos", "campañas"... */
    itemLabel  : { type: String, default: 'registros' },
    /** true cuando "Todos" recortó el resultado. */
    capped     : { type: Boolean, default: false },
    /** Tope duro de "Todos" (lo manda el backend). */
    capLimit   : { type: Number, default: 5000 },
});

const emit = defineEmits(['update:page', 'update:perPage']);

const sizeOptions = [
    { label: '10',    value: 10 },
    { label: '20',    value: 20 },
    { label: '50',    value: 50 },
    { label: '100',   value: 100 },
    { label: '250',   value: 250 },
    { label: '500',   value: 500 },
    { label: 'Todos', value: 'all' },
];

const formatNumber = (n) => new Intl.NumberFormat('es-MX').format(n ?? 0);
const formattedTotal = computed(() => formatNumber(props.total));

// Al cambiar el tamaño siempre se vuelve a la página 1: la página 7 de 10 en 1 no existe en 500.
function onSizeChange(value) {
    emit('update:perPage', value);
}
</script>

<style scoped>
.table-paginator {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 10px;
    font-size: .85rem;
    color: var(--p-text-muted-color);
}

.pager-size { display: flex; align-items: center; gap: 6px; }
.pager-lbl  { white-space: nowrap; }
.pager-nav  { display: flex; align-items: center; gap: 2px; }
.pager-info { white-space: nowrap; }

.pager-total {
    margin-left: auto;
    font-weight: 600;
    color: var(--p-text-color);
}

.pager-cap {
    display: flex;
    align-items: center;
    gap: 6px;
    width: 100%;
    color: var(--p-orange-600, #c2410c);
    font-size: .8rem;
}

@media (max-width: 640px) {
    .pager-total { margin-left: 0; }
}
</style>
