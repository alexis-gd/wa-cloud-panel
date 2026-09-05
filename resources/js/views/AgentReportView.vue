<template>
    <div class="agent-report">
        <div class="stats-row" v-if="totals">
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num">{{ totals.received }}</span>
                        <span class="stat-lbl">Recibidas en el periodo</span>
                    </div>
                </template>
            </Card>
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num active">{{ totals.open_now }}</span>
                        <span class="stat-lbl">Abiertas ahora</span>
                    </div>
                </template>
            </Card>
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num">{{ rows.length }}</span>
                        <span class="stat-lbl">Personas en el reporte</span>
                    </div>
                </template>
            </Card>
        </div>

        <Card>
            <template #title>
                <span class="table-title">
                    Conversaciones por agente
                    <i class="pi pi-question-circle title-help" v-tooltip.top="tableHelp"></i>
                </span>
            </template>

            <template #content>
                <div class="filter-row">
                    <div class="filter-field">
                        <label>Desde</label>
                        <DatePicker v-model="from" date-format="yy-mm-dd" show-icon :max-date="to ?? undefined" />
                    </div>
                    <div class="filter-field">
                        <label>Hasta</label>
                        <DatePicker v-model="to" date-format="yy-mm-dd" show-icon :min-date="from ?? undefined" />
                    </div>
                    <div class="filter-field">
                        <label>Agente</label>
                        <Select
                            v-model="userId"
                            :options="userOptions"
                            option-label="label"
                            option-value="value"
                            placeholder="Todos"
                            show-clear
                        />
                    </div>
                    <div class="filter-actions">
                        <Button label="Ver" icon="pi pi-search" :loading="loading" @click="cargar" />
                        <Button label="Hoy" text severity="secondary" @click="verHoy" />
                    </div>
                </div>

                <div class="export-row">
                    <Button
                        label="Descargar Excel"
                        icon="pi pi-file-excel"
                        severity="secondary"
                        :disabled="!rows.length"
                        @click="descargar('xlsx')"
                    />
                    <Button
                        label="Descargar PDF"
                        icon="pi pi-file-pdf"
                        severity="secondary"
                        :disabled="!rows.length"
                        @click="descargar('pdf')"
                    />
                </div>

                <div class="table-scroll mt-3">
                <DataTable :value="rows" :loading="loading" size="small" stripedRows>
                    <Column field="agent" header="Agente" style="min-width: 180px" />
                    <Column header="Rol" style="width: 140px">
                        <template #body="{ data }">
                            <Tag :value="rolLabel(data.role)" :severity="rolSeverity(data.role)" />
                        </template>
                    </Column>
                    <Column header="Recibidas en el periodo" style="width: 190px">
                        <template #body="{ data }">
                            <span class="num" :class="{ zero: !data.received }">{{ data.received }}</span>
                        </template>
                    </Column>
                    <Column header="Abiertas ahora" style="width: 150px">
                        <template #body="{ data }">
                            <span class="num" :class="{ zero: !data.open_now }">{{ data.open_now }}</span>
                        </template>
                    </Column>
                    <template #empty>
                        <span class="empty-msg">Sin datos para este periodo.</span>
                    </template>
                    <template #footer v-if="rows.length">
                        <div class="table-footer">
                            <span>Total</span>
                            <span class="num">{{ totals.received }} recibidas</span>
                            <span class="num">{{ totals.open_now }} abiertas</span>
                        </div>
                    </template>
                </DataTable>
                </div>

                <p class="legend">
                    <strong>Recibidas en el periodo</strong>: se le asignaron entre las fechas del filtro.
                    <strong>Abiertas ahora</strong>: las que tiene a su cargo en este momento, sin importar
                    cuándo se le asignaron - por eso no cambia con el filtro de fecha.
                </p>
            </template>
        </Card>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useToast } from 'primevue/usetoast';
import Card       from 'primevue/card';
import Button     from 'primevue/button';
import Select     from 'primevue/select';
import DatePicker from 'primevue/datepicker';
import DataTable  from 'primevue/datatable';
import Column     from 'primevue/column';
import Tag        from 'primevue/tag';
import { api }    from '../api.js';

const toast = useToast();

const rows    = ref([]);
const totals  = ref({ received: 0, open_now: 0 });
const loading = ref(false);
const users   = ref([]);
const userId  = ref(null);

const from = ref(new Date());
const to   = ref(new Date());

const userOptions = computed(() => users.value.map(u => ({ label: u.name, value: u.id })));

const tableHelp =
    'Cuántas conversaciones lleva cada quien. "Recibidas en el periodo" responde al filtro de '
    + 'fecha: son las que se le asignaron entre esas fechas. "Abiertas ahora" es la foto de este '
    + 'momento y NO cambia con el filtro. Un agente puede haber recibido 12 hoy y tener 40 '
    + 'abiertas porque arrastra de días anteriores. Los botones descargan la misma tabla con '
    + 'los filtros aplicados.';

// El backend habla en 'Y-m-d' de hora de México. Se formatea desde las partes locales del
// Date y no con toISOString(), que convierte a UTC y puede correr el día.
function comoFecha(d) {
    if (!d) return null;
    const mes = String(d.getMonth() + 1).padStart(2, '0');
    const dia = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${mes}-${dia}`;
}

const filtros = () => ({
    from: comoFecha(from.value),
    to  : comoFecha(to.value),
    ...(userId.value ? { user_id: userId.value } : {}),
});

const rolLabel = (r) => ({ admin: 'Administrador', operator: 'Operador', agent: 'Agente' }[r] ?? r);
const rolSeverity = (r) => ({ admin: 'warn', operator: 'info', agent: 'success' }[r] ?? 'secondary');

async function cargar() {
    loading.value = true;
    const res = await api.agentReport(filtros());
    loading.value = false;

    if (res.status !== 'ok') {
        toast.add({ severity: 'error', summary: 'No se pudo cargar el reporte', detail: res.message, life: 5000 });
        return;
    }

    rows.value   = res.data ?? [];
    totals.value = res.meta?.totals ?? { received: 0, open_now: 0 };
}

function verHoy() {
    from.value = new Date();
    to.value   = new Date();
    cargar();
}

function descargar(format) {
    api.downloadAgentReport({ ...filtros(), format });
}

onMounted(async () => {
    const res = await api.assignableUsers();
    users.value = res.status === 'ok' ? (res.data ?? []) : [];
    cargar();
});
</script>

<style scoped>
.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.stat     { display: flex; flex-direction: column; gap: 2px; }
.stat-num { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
.stat-num.active { color: var(--p-primary-600); }
.stat-lbl { font-size: .8rem; color: var(--p-text-muted-color); }

.table-title { display: inline-flex; align-items: center; gap: 8px; }
.title-help  { font-size: .9rem; color: var(--p-text-muted-color); cursor: help; }

.filter-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: flex-end;
}

.filter-field       { display: flex; flex-direction: column; gap: 4px; }
.filter-field label { font-size: .78rem; color: var(--p-text-muted-color); font-weight: 600; }
.filter-actions     { display: flex; gap: 8px; }

.export-row {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 12px;
}

.table-scroll { overflow-x: auto; }
.mt-3 { margin-top: 12px; }

.num       { font-variant-numeric: tabular-nums; font-weight: 600; }
.num.zero  { color: var(--p-text-muted-color); font-weight: 400; }
.empty-msg { color: var(--p-text-muted-color); font-size: .85rem; }

.table-footer {
    display: flex;
    gap: 20px;
    justify-content: flex-end;
    font-size: .85rem;
    font-weight: 600;
}

.legend {
    margin: 12px 0 0;
    font-size: .78rem;
    color: var(--p-text-muted-color);
    line-height: 1.5;
}

@media (max-width: 640px) {
    .filter-field, .filter-actions { width: 100%; }
    .filter-field :deep(.p-datepicker),
    .filter-field :deep(.p-select) { width: 100%; }
    .filter-actions :deep(.p-button) { flex: 1; justify-content: center; }
    .export-row :deep(.p-button) { flex: 1; justify-content: center; }
}
</style>
