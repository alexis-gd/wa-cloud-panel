<template>
    <div class="otp">
        <Card>
            <template #title>
                <div class="head">
                    <span>Códigos de verificación</span>
                    <div class="head-actions">
                        <span class="p-input-icon-left search">
                            <InputText
                                v-model="q"
                                placeholder="Buscar número o referencia"
                                @keyup.enter="reload"
                            />
                        </span>
                        <Select
                            v-model="estado"
                            :options="opcionesEstado"
                            option-label="label"
                            option-value="value"
                            @change="reload"
                            class="estado-select"
                        />
                        <Button icon="pi pi-refresh" text @click="reload" :loading="loading" />
                    </div>
                </div>
            </template>

            <template #content>
                <p class="desc">
                    Códigos que la app del cliente pidió por el API. Esta pantalla es
                    <b>solo de lectura</b>: desde aquí no se manda ni se reenvía ninguno. Sirve para
                    contestar si a una persona le llegó su código o no.
                </p>

                <div class="resumen">
                    <div class="tarjeta">
                        <span class="n">{{ resumen.pedidos_hoy }}</span>
                        <small>Pedidos hoy</small>
                    </div>
                    <div class="tarjeta">
                        <span class="n ok">{{ resumen.verificados }}</span>
                        <small>Verificados</small>
                    </div>
                    <div class="tarjeta">
                        <span class="n mal">{{ resumen.fallidos }}</span>
                        <small>No salieron</small>
                    </div>
                    <div class="tarjeta" v-if="resumen.cupo_restante !== null">
                        <span class="n">{{ resumen.cupo_restante }}</span>
                        <small>Cupo que queda hoy</small>
                    </div>
                </div>

                <div class="table-scroll">
                    <DataTable :value="filas" :loading="loading" data-key="id">
                        <template #empty>Todavía no se ha pedido ningún código.</template>

                        <Column header="Pedido" style="width: 150px">
                            <template #body="{ data }">{{ data.created_at }}</template>
                        </Column>
                        <Column header="Número" style="width: 160px">
                            <template #body="{ data }">{{ data.phone }}</template>
                        </Column>
                        <Column header="Referencia">
                            <template #body="{ data }">
                                <span v-if="data.reference">{{ data.reference }}</span>
                                <span v-else class="vacio">-</span>
                            </template>
                        </Column>
                        <Column header="Estado" style="width: 160px">
                            <template #body="{ data }">
                                <Tag
                                    :value="etiqueta(data.status).texto"
                                    :severity="etiqueta(data.status).color"
                                />
                            </template>
                        </Column>
                        <Column header="Intentos" style="width: 100px">
                            <template #body="{ data }">{{ data.attempts }}</template>
                        </Column>
                        <Column header="Detalle">
                            <template #body="{ data }">
                                <span v-if="data.error_message" class="motivo">{{ data.error_message }}</span>
                                <span v-else-if="data.verified_at" class="vacio">Entró a las {{ data.verified_at }}</span>
                                <span v-else-if="data.sent_at" class="vacio">Enviado {{ data.sent_at }}</span>
                                <span v-else class="vacio">-</span>
                            </template>
                        </Column>
                    </DataTable>
                </div>

                <TablePaginator
                    :page="page"
                    :total-pages="pages"
                    :total="total"
                    :per-page="perPage"
                    item-label="códigos"
                    @update:page="irAPagina"
                    @update:per-page="cambiarTamano"
                />
            </template>
        </Card>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { useToast } from 'primevue/usetoast';
import Card      from 'primevue/card';
import Button    from 'primevue/button';
import InputText from 'primevue/inputtext';
import Select    from 'primevue/select';
import DataTable from 'primevue/datatable';
import Column    from 'primevue/column';
import Tag       from 'primevue/tag';
import { api }   from '../api.js';
import TablePaginator from '../components/TablePaginator.vue';

const toast = useToast();

const filas   = ref([]);
const total   = ref(0);
const page    = ref(1);
const pages   = ref(1);
const perPage = ref(30);
const loading = ref(false);
const q       = ref('');
const estado  = ref(null);
const resumen = ref({ pedidos_hoy: 0, verificados: 0, fallidos: 0, cupo_restante: null });

const opcionesEstado = [
    { label: 'Todos',       value: null },
    { label: 'Enviado',     value: 'sent' },
    { label: 'Verificado',  value: 'verified' },
    { label: 'Caducado',    value: 'expired' },
    { label: 'No salió',    value: 'failed' },
    { label: 'En cola',     value: 'pending' },
];

/**
 * Los estados viajan en inglés y nunca deben llegar así al operador.
 *
 * Al agregar un estado nuevo en el backend hay que agregarlo también aquí: lo que no esté en
 * este mapa sale crudo en pantalla.
 */
const etiquetas = {
    pending  : { texto: 'En cola',    color: 'secondary' },
    sent     : { texto: 'Enviado',    color: 'info' },
    verified : { texto: 'Verificado', color: 'success' },
    expired  : { texto: 'Caducado',   color: 'warn' },
    failed   : { texto: 'No salió',   color: 'danger' },
};

function etiqueta(estado) {
    return etiquetas[estado] ?? { texto: estado, color: 'secondary' };
}

async function load() {
    loading.value = true;

    const res = await api.otpLog({
        q        : q.value,
        status   : estado.value ?? '',
        page     : page.value,
        per_page : perPage.value,
    });

    if (res.status === 'ok') {
        filas.value   = res.data;
        total.value   = res.meta.total;
        pages.value   = Math.max(1, Math.ceil(res.meta.total / (res.meta.per_page || 30)));
        resumen.value = res.meta.resumen;
    } else {
        toast.add({ severity: 'error', summary: 'No se pudo cargar', detail: res.message, life: 5000 });
    }

    loading.value = false;
}

function reload() {
    page.value = 1;
    load();
}

function irAPagina(n) {
    page.value = n;
    load();
}

function cambiarTamano(n) {
    perPage.value = n;
    page.value    = 1;
    load();
}

onMounted(load);
</script>

<style scoped>
.head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
}

.head-actions {
    display: flex;
    gap: .5rem;
    align-items: center;
    flex-wrap: wrap;
}

.search :deep(input) { width: 230px; }
.estado-select { width: 160px; }

.desc {
    margin: 0 0 1rem;
    color: var(--p-text-muted-color);
    font-size: .9rem;
}

.resumen {
    display: flex;
    gap: .75rem;
    flex-wrap: wrap;
    margin-bottom: 1rem;
}

.tarjeta {
    flex: 1 1 140px;
    background: var(--p-surface-100);
    border-radius: 8px;
    padding: .75rem 1rem;
    display: flex;
    flex-direction: column;
}

.tarjeta .n {
    font-size: 1.5rem;
    font-weight: 600;
}

.tarjeta .n.ok  { color: var(--p-green-600); }
.tarjeta .n.mal { color: var(--p-red-500); }

.tarjeta small { color: var(--p-text-muted-color); }

.table-scroll { overflow-x: auto; }

.vacio  { color: var(--p-text-muted-color); }
.motivo { color: var(--p-red-500); font-size: .85rem; }

@media (max-width: 640px) {
    .search :deep(input) { width: 100%; }
    .head-actions { width: 100%; }
}
</style>
