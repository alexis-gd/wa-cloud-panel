<template>
    <div class="sms-replies">
        <Card>
            <template #title>
                <div class="head">
                    <span>Respuestas SMS</span>
                    <div class="head-actions">
                        <span class="p-input-icon-left search">
                            <InputText
                                v-model="q"
                                placeholder="Buscar número, nombre o texto"
                                @keyup.enter="reload"
                            />
                        </span>
                        <Select
                            v-model="actionFilter"
                            :options="actionOptions"
                            option-label="label"
                            option-value="value"
                            @change="reload"
                            class="action-select"
                        />
                        <Button icon="pi pi-refresh" text @click="reload" :loading="loading" />
                    </div>
                </div>
            </template>
            <template #content>
                <p class="desc">
                    Respuestas de los contactos por SMS, <b>agrupadas por contacto</b>. Da click en una
                    fila para ver todos sus mensajes. Si alguien responde <b>SÍ</b> o <b>INFO</b> se marca
                    <b>Interesado</b>; si responde <b>STOP/BAJA</b> se da de baja automáticamente
                    (<b>Baja automática</b>).
                </p>

                <div class="table-scroll">
                <DataTable
                    :value="rows"
                    :loading="loading"
                    paginator
                    :rows="30"
                    :total-records="total"
                    lazy
                    @page="onPage"
                    v-model:expandedRows="expandedRows"
                    data-key="contact_id"
                >
                    <template #empty>Sin respuestas SMS todavía.</template>

                    <Column expander style="width: 42px" />

                    <Column header="Fecha" style="width: 150px">
                        <template #body="{ data }">{{ data.last_received_at }}</template>
                    </Column>
                    <Column header="Contacto">
                        <template #body="{ data }">
                            <div class="num">
                                <span v-if="data.contact_name" class="name">{{ data.contact_name }}</span>
                                <small>{{ data.from_number }}</small>
                            </div>
                        </template>
                    </Column>
                    <Column header="Último mensaje">
                        <template #body="{ data }"><span class="msg">{{ data.last_body }}</span></template>
                    </Column>
                    <Column header="Msgs" style="width: 80px">
                        <template #body="{ data }"><span class="count">{{ data.count }}</span></template>
                    </Column>
                    <Column header="Acción" style="width: 170px">
                        <template #body="{ data }">
                            <Tag
                                v-if="actionTag(data.summary_action)"
                                :value="actionTag(data.summary_action).value"
                                :severity="actionTag(data.summary_action).severity"
                                :icon="actionTag(data.summary_action).icon"
                            />
                            <span v-else class="muted">-</span>
                        </template>
                    </Column>

                    <template #expansion="{ data }">
                        <div class="thread">
                            <div v-for="m in data.messages" :key="m.id" class="thread-row">
                                <span class="thread-date">{{ m.received_at }}</span>
                                <span class="thread-body">{{ m.body }}</span>
                                <Tag
                                    v-if="actionTag(m.action)"
                                    :value="actionTag(m.action).value"
                                    :severity="actionTag(m.action).severity"
                                    :icon="actionTag(m.action).icon"
                                />
                                <span v-else class="muted thread-action">-</span>
                            </div>
                        </div>
                    </template>
                </DataTable>
                </div>
            </template>
        </Card>
    </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import { useToast } from 'primevue/usetoast';
import Card         from 'primevue/card';
import Button       from 'primevue/button';
import InputText    from 'primevue/inputtext';
import Select       from 'primevue/select';
import DataTable    from 'primevue/datatable';
import Column       from 'primevue/column';
import Tag          from 'primevue/tag';
import { api }      from '../api.js';
import { initEcho } from '../echo.js';

const toast = useToast();

const rows         = ref([]);
const total        = ref(0);
const page         = ref(1);
const loading      = ref(false);
const q            = ref('');
const actionFilter = ref(null);
const expandedRows = ref({});

const actionOptions = [
    { label: 'Todas',       value: null },
    { label: 'Interesados', value: 'interested' },
    { label: 'Bajas',       value: 'opt_out' },
];

// Etiqueta visual según la acción del mensaje/grupo. null -> sin tag (se muestra "-").
function actionTag(action) {
    if (action === 'opt_out')    return { value: 'Baja automática', severity: 'danger',  icon: 'pi pi-ban' };
    if (action === 'interested') return { value: 'Interesado',      severity: 'success', icon: 'pi pi-check' };
    return null;
}

async function load() {
    loading.value = true;
    const res = await api.smsInbound({
        page: page.value,
        ...(q.value ? { q: q.value } : {}),
        ...(actionFilter.value ? { action: actionFilter.value } : {}),
    });
    if (res.status === 'ok') {
        rows.value  = res.data;
        total.value = res.meta.total;
    }
    loading.value = false;
}

function reload() {
    page.value = 1;
    load();
}

function onPage(e) {
    page.value = e.page + 1;
    load();
}

// ── Tiempo real (Soketi): cuando entra una respuesta SMS de un contacto, avisa al
// instante. Si estamos en la primera pagina sin filtros, refresca la lista (refetch-on-event
// debounced, no polling). Con filtros o en otra pagina, solo el toast (no interrumpe la vista).
let echoChannel    = null;
let reloadDebounce = null;

function subscribeRealtime() {
    const echo = initEcho();
    if (! echo) return;

    echoChannel = echo.private('conversations');
    echoChannel.listen('.inbound.message', (e) => {
        if (e.channel !== 'sms') return; // esta vista es solo SMS

        toast.add({
            severity: 'info',
            summary : 'Nueva respuesta SMS',
            detail  : `${e.contact_name || 'Contacto'}: ${(e.body ?? '').slice(0, 40)}`,
            life    : 4000,
        });

        // Solo refrescar si estamos viendo la lista fresca (pagina 1, sin filtros).
        if (page.value === 1 && !q.value && !actionFilter.value) {
            clearTimeout(reloadDebounce);
            reloadDebounce = setTimeout(load, 500);
        }
    });
}

onMounted(() => {
    load();
    subscribeRealtime();
});

onUnmounted(() => {
    clearTimeout(reloadDebounce);
    if (echoChannel) {
        echoChannel.stopListening('.inbound.message');
        echoChannel = null;
    }
});
</script>

<style scoped>
/* Tabla scrollea en su propia caja en móvil, sin estirar la página. */
.table-scroll    { overflow-x: auto; }
.head            { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.head-actions    { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.search :deep(input) { width: 240px; max-width: 100%; }
.action-select   { min-width: 140px; }
.desc            { font-size: .82rem; color: var(--p-text-muted-color); margin-bottom: 16px; }
.num             { display: flex; flex-direction: column; }
.num .name       { font-weight: 600; }
.num small       { color: var(--p-text-muted-color); font-size: .76rem; }
.msg             { white-space: pre-wrap; word-break: break-word; }
.count           { font-variant-numeric: tabular-nums; font-weight: 600; }
.muted           { color: var(--p-text-muted-color); }

/* ── Hilo expandido ─────────────────────────────────────────── */
.thread          { padding: 4px 8px 8px 42px; display: flex; flex-direction: column; gap: 6px; }
.thread-row      { display: flex; align-items: center; gap: 12px; padding: 6px 0; border-bottom: 1px solid var(--p-surface-100); }
.thread-row:last-child { border-bottom: none; }
.thread-date     { color: var(--p-text-muted-color); font-size: .78rem; width: 120px; flex-shrink: 0; }
.thread-body     { flex: 1; white-space: pre-wrap; word-break: break-word; }
.thread-action   { width: 150px; flex-shrink: 0; text-align: right; }
</style>
