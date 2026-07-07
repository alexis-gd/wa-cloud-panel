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
                                placeholder="Buscar número o texto"
                                @keyup.enter="reload"
                            />
                        </span>
                        <ToggleButton
                            v-model="optOutOnly"
                            on-label="Solo bajas"
                            off-label="Todas"
                            @change="reload"
                        />
                        <Button icon="pi pi-refresh" text @click="reload" :loading="loading" />
                    </div>
                </div>
            </template>
            <template #content>
                <p class="desc">
                    Mensajes que los contactos respondieron por SMS. Es una lista de solo lectura
                    (no un chat). Si alguien responde <b>STOP/BAJA</b>, el sistema lo da de baja
                    automáticamente y lo verás marcado como <b>Baja automática</b>.
                </p>

                <DataTable
                    :value="rows"
                    :loading="loading"
                    paginator
                    :rows="30"
                    :total-records="total"
                    lazy
                    @page="onPage"
                    data-key="id"
                >
                    <template #empty>Sin respuestas SMS todavía.</template>

                    <Column header="Fecha" style="width: 150px">
                        <template #body="{ data }">{{ data.received_at }}</template>
                    </Column>
                    <Column header="Número">
                        <template #body="{ data }">
                            <div class="num">
                                <span>{{ data.from_number }}</span>
                                <small v-if="data.contact_name">{{ data.contact_name }}</small>
                            </div>
                        </template>
                    </Column>
                    <Column header="Mensaje">
                        <template #body="{ data }"><span class="msg">{{ data.body }}</span></template>
                    </Column>
                    <Column header="Acción" style="width: 160px">
                        <template #body="{ data }">
                            <Tag
                                v-if="data.action === 'opt_out'"
                                value="Baja automática"
                                severity="danger"
                                icon="pi pi-ban"
                            />
                            <span v-else class="muted">-</span>
                        </template>
                    </Column>
                </DataTable>
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
import ToggleButton from 'primevue/togglebutton';
import DataTable    from 'primevue/datatable';
import Column       from 'primevue/column';
import Tag          from 'primevue/tag';
import { api }      from '../api.js';
import { initEcho } from '../echo.js';

const toast = useToast();

const rows       = ref([]);
const total      = ref(0);
const page       = ref(1);
const loading    = ref(false);
const q          = ref('');
const optOutOnly = ref(false);

async function load() {
    loading.value = true;
    const res = await api.smsInbound({
        page: page.value,
        ...(q.value ? { q: q.value } : {}),
        ...(optOutOnly.value ? { opt_out_only: 1 } : {}),
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
let echoChannel   = null;
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
        if (page.value === 1 && !q.value && !optOutOnly.value) {
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
.head            { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.head-actions    { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.search :deep(input) { width: 220px; }
.desc            { font-size: .82rem; color: var(--p-text-muted-color); margin-bottom: 16px; }
.num             { display: flex; flex-direction: column; }
.num small       { color: var(--p-text-muted-color); font-size: .76rem; }
.msg             { white-space: pre-wrap; word-break: break-word; }
.muted           { color: var(--p-text-muted-color); }
</style>
