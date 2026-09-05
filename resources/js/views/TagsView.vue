<template>
    <div class="tags-view">
        <!-- Resumen -->
        <div class="stats-row">
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num">{{ tags.length }}</span>
                        <span class="stat-lbl">Etiquetas</span>
                    </div>
                </template>
            </Card>
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num active">{{ taggedContacts }}</span>
                        <span class="stat-lbl">Contactos etiquetados</span>
                    </div>
                </template>
            </Card>
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num empty">{{ unusedCount }}</span>
                        <span class="stat-lbl">Sin usar</span>
                    </div>
                </template>
            </Card>
        </div>

        <Card>
            <template #title>
                <span class="table-title">
                    Catálogo de etiquetas
                    <i class="pi pi-question-circle title-help" v-tooltip.top="tableHelp"></i>
                </span>
            </template>

            <template #content>
                <div class="filter-row">
                    <InputText v-model="search" placeholder="Buscar etiqueta..." fluid />
                    <Button label="Nueva etiqueta" icon="pi pi-plus" @click="openCreate" />
                </div>

                <div class="table-scroll mt-3">
                <DataTable :value="pageRows" :loading="loading" size="small" stripedRows>
                    <Column field="name" header="Etiqueta" style="min-width: 180px">
                        <template #body="{ data }">
                            <span class="tag-chip">{{ data.name }}</span>
                        </template>
                    </Column>
                    <Column field="slug" header="Identificador" style="min-width: 150px">
                        <template #body="{ data }">
                            <code class="slug" v-tooltip.top="slugHelp">{{ data.slug }}</code>
                        </template>
                    </Column>
                    <Column header="Contactos" style="width: 130px">
                        <template #body="{ data }">
                            <Button
                                v-if="data.contacts_count"
                                :label="String(data.contacts_count)"
                                icon="pi pi-users"
                                text
                                size="small"
                                severity="secondary"
                                @click="verContactos(data)"
                                v-tooltip.top="'Ver estos contactos'"
                            />
                            <span v-else class="muted">0</span>
                        </template>
                    </Column>
                    <Column header="Campañas" style="width: 110px">
                        <template #body="{ data }">
                            <span :class="data.campaigns_count ? '' : 'muted'">{{ data.campaigns_count ?? 0 }}</span>
                        </template>
                    </Column>
                    <Column header="Creada" style="width: 120px">
                        <template #body="{ data }">
                            <span class="date-cell">{{ data.created_at?.substring(0, 10) }}</span>
                        </template>
                    </Column>
                    <Column header="" style="width: 110px">
                        <template #body="{ data }">
                            <div class="row-actions">
                                <Button
                                    icon="pi pi-pencil"
                                    text
                                    size="small"
                                    severity="secondary"
                                    @click="openRename(data)"
                                    v-tooltip.top="'Renombrar'"
                                />
                                <Button
                                    icon="pi pi-trash"
                                    text
                                    size="small"
                                    severity="danger"
                                    :loading="checkingId === data.id"
                                    @click="borrar(data)"
                                    v-tooltip.top="'Borrar etiqueta'"
                                />
                            </div>
                        </template>
                    </Column>
                    <template #empty>
                        <span class="empty-msg">
                            {{ search ? 'Ninguna etiqueta coincide con la búsqueda.' : 'Todavía no hay etiquetas.' }}
                        </span>
                    </template>
                </DataTable>
                </div>

                <TablePaginator
                    :page="page"
                    :total-pages="totalPages"
                    :total="filtered.length"
                    :per-page="perPage"
                    item-label="etiquetas"
                    @update:page="p => page = p"
                    @update:per-page="cambiarTamano"
                />
            </template>
        </Card>
    </div>

    <ConfirmDialog />

    <!-- Crear / renombrar -->
    <Dialog v-model:visible="formDialog" :header="editing ? 'Renombrar etiqueta' : 'Nueva etiqueta'" modal style="width: 400px">
        <div class="edit-field">
            <label>Nombre</label>
            <InputText v-model="formName" placeholder="Ej. VIP, Mazatlán, Referido" @keyup.enter="guardar" fluid autofocus />
            <small v-if="editing" class="field-hint">
                El identificador (<code>{{ editing.slug }}</code>) no cambia: es con el que el
                Excel de importación reconoce esta etiqueta.
            </small>
            <div v-if="formError" class="form-error">{{ formError }}</div>
        </div>
        <template #footer>
            <Button label="Cancelar" text @click="formDialog = false" />
            <Button label="Guardar" :loading="saving" :disabled="!formName.trim()" @click="guardar" />
        </template>
    </Dialog>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter } from 'vue-router';
import { useConfirm } from 'primevue/useconfirm';
import { useToast }   from 'primevue/usetoast';
import Card          from 'primevue/card';
import Button        from 'primevue/button';
import InputText     from 'primevue/inputtext';
import DataTable     from 'primevue/datatable';
import Column        from 'primevue/column';
import Dialog        from 'primevue/dialog';
import ConfirmDialog from 'primevue/confirmdialog';
import { api }       from '../api.js';
import TablePaginator from '../components/TablePaginator.vue';

const router  = useRouter();
const confirm = useConfirm();
const toast   = useToast();

const tags       = ref([]);
const loading    = ref(false);
const search     = ref('');
const page       = ref(1);
const perPage    = ref(20);
const checkingId = ref(null);

const formDialog = ref(false);
const formName   = ref('');
const editing    = ref(null);   // null = alta, objeto = renombrar
const saving     = ref(false);
// El error de este formulario vive aqui y se pinta debajo del campo, no en un toast.
const formError  = ref('');

// La búsqueda y el paginado son del lado del navegador a propósito: las etiquetas son
// decenas, y el endpoint devuelve la lista completa porque los selectores de Contactos y
// Campañas la necesitan entera. Paginar en el servidor solo agregaría idas y vueltas.
const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();
    return term
        ? tags.value.filter(t => t.name.toLowerCase().includes(term))
        : tags.value;
});

const totalPages = computed(() => Math.max(1, Math.ceil(filtered.value.length / sizeOf(perPage.value))));

const pageRows = computed(() => {
    const size = sizeOf(perPage.value);
    const from = (page.value - 1) * size;
    return filtered.value.slice(from, from + size);
});

const taggedContacts = computed(() => tags.value.reduce((n, t) => n + (t.contacts_count ?? 0), 0));
const unusedCount    = computed(() => tags.value.filter(t => !t.contacts_count).length);

// "Todos" del paginador: aquí no hace falta tope, la lista completa ya está en memoria.
function sizeOf(value) {
    return value === 'all' ? Math.max(1, filtered.value.length) : value;
}

function cambiarTamano(size) {
    perPage.value = size;
    page.value    = 1;
}

// Al buscar, volver a la página 1: la 3 de 5 no existe con 2 resultados.
watch(search, () => { page.value = 1; });

const tableHelp =
    'Las etiquetas sirven para agrupar contactos: una campaña se le puede mandar solo a los de '
    + 'una etiqueta. Contactos = cuántos la tienen ahora. Campañas = cuántas la tienen como '
    + 'destinatarios. Renombrar cambia solo el nombre visible; el identificador se queda fijo '
    + 'para que el Excel de importación siga reconociendo la etiqueta.';

const slugHelp =
    'Identificador interno. Es con lo que el Excel de importación reconoce la etiqueta, por eso '
    + 'no cambia aunque la renombres.';

async function cargar() {
    loading.value = true;
    const res = await api.tags();
    tags.value = res.status === 'ok' ? (res.data ?? []) : [];
    loading.value = false;
}

function verContactos(tag) {
    router.push({ path: '/contacts', query: { tag: tag.id } });
}

// ── Alta y renombrado ────────────────────────────────────────────────────────
function openCreate() {
    editing.value    = null;
    formName.value   = '';
    formError.value  = '';
    formDialog.value = true;
}

function openRename(tag) {
    editing.value    = tag;
    formName.value   = tag.name;
    formError.value  = '';
    formDialog.value = true;
}

/**
 * Traduce la respuesta del backend a algo que el operador pueda accionar. Nunca se muestra
 * el texto crudo de la base de datos: un "Integrity constraint violation 1062" no le dice a
 * nadie que le ponga otro nombre a la etiqueta.
 */
function mensajeDeError(res) {
    if (res.code === 'DUPLICATE_SLUG') return res.message;

    // Laravel devuelve los errores de validacion agrupados por campo.
    const deValidacion = res.errors?.name?.[0];
    if (deValidacion) return 'Ya existe una etiqueta con ese nombre. Ponle otro.';

    return res.message ?? 'No se pudo guardar la etiqueta. Intenta con otro nombre.';
}

async function guardar() {
    const name = formName.value.trim();
    if (!name) return;

    formError.value = '';
    saving.value = true;
    const res = editing.value
        ? await api.renameTag(editing.value.id, name)
        : await api.createTag(name);
    saving.value = false;

    if (res.status !== 'ok') {
        // El error se queda DENTRO del formulario, junto al campo que hay que corregir. En un
        // toast el operador lo lee, se va, y se queda sin saber qué escribir distinto.
        formError.value = mensajeDeError(res);
        return;
    }

    formDialog.value = false;
    toast.add({
        severity : 'success',
        summary  : editing.value ? 'Etiqueta renombrada' : 'Etiqueta creada',
        detail   : name,
        life     : 3000,
    });
    cargar();
}

// ── Borrado ──────────────────────────────────────────────────────────────────
// Mismo flujo que en Contactos: se pide el conteo real antes de confirmar y el backend
// bloquea si una campaña sin enviar usa la etiqueta (se quedaría sin segmento).
async function borrar(tag) {
    checkingId.value = tag.id;
    const res = await api.tagUsage(tag.id);
    checkingId.value = null;

    if (res.status !== 'ok') {
        toast.add({ severity: 'error', summary: 'No se pudo consultar la etiqueta', detail: res.message, life: 5000 });
        return;
    }

    const usage = res.data;

    if (usage.blocked) {
        toast.add({ severity: 'warn', summary: 'No se puede borrar', detail: usage.reason, life: 10000 });
        return;
    }

    confirm.require({
        header     : `Borrar la etiqueta "${tag.name}"`,
        message    : mensajeBorrado(usage),
        icon       : 'pi pi-exclamation-triangle',
        acceptLabel: 'Borrar etiqueta',
        rejectLabel: 'Cancelar',
        acceptClass: 'p-button-danger',
        accept     : async () => {
            const del = await api.deleteTag(tag.id);

            if (del.status !== 'ok') {
                toast.add({ severity: 'error', summary: 'No se pudo borrar', detail: del.message, life: 10000 });
                return;
            }

            toast.add({
                severity : 'success',
                summary  : 'Etiqueta borrada',
                detail   : `${del.data?.contacts_untagged ?? 0} contacto(s) dejaron de tenerla. Ningún contacto se eliminó.`,
                life     : 4000,
            });
            cargar();
        },
    });
}

function mensajeBorrado(usage) {
    const partes = [];

    partes.push(usage.contacts === 0
        ? 'Ningún contacto tiene esta etiqueta.'
        : `${usage.contacts} contacto(s) dejarán de tenerla. Los contactos NO se eliminan.`);

    // "Segmento" es palabra nuestra, no del operador. Se dice con las palabras de la pantalla
    // de Campañas, donde ese campo se llama "Destinatarios".
    if (usage.campaigns > 0) {
        partes.push(`${usage.campaigns} campaña(s) que ya se enviaron dejarán de mostrar esta etiqueta en sus destinatarios. Lo que ya se envió no cambia: sus mensajes y resultados siguen igual.`);
    }

    partes.push('Esta acción no se puede deshacer.');

    return partes.join(' ');
}

onMounted(cargar);
</script>

<style scoped>
.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
    margin-bottom: 16px;
}

.stat     { display: flex; flex-direction: column; gap: 2px; }
.stat-num { font-size: 1.6rem; font-weight: 700; line-height: 1.1; }
.stat-num.active { color: var(--p-primary-600); }
.stat-num.empty  { color: var(--p-text-muted-color); }
.stat-lbl { font-size: .8rem; color: var(--p-text-muted-color); }

.table-title { display: inline-flex; align-items: center; gap: 8px; }
.title-help  { font-size: .9rem; color: var(--p-text-muted-color); cursor: help; }

.filter-row { display: flex; gap: 8px; }
.filter-row :deep(.p-button) { flex-shrink: 0; white-space: nowrap; }

.table-scroll { overflow-x: auto; }
.mt-3 { margin-top: 12px; }

.tag-chip {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 12px;
    background: var(--p-primary-50);
    color: var(--p-primary-700);
    font-size: .82rem;
    font-weight: 600;
}

.slug        { font-size: .78rem; color: var(--p-text-muted-color); cursor: help; }
.muted       { color: var(--p-text-muted-color); }
.date-cell   { font-size: .82rem; color: var(--p-text-muted-color); }
.empty-msg   { color: var(--p-text-muted-color); font-size: .85rem; }
.row-actions { display: flex; gap: 2px; align-items: center; }

.edit-field       { display: flex; flex-direction: column; gap: 6px; }
.edit-field label { font-size: .85rem; font-weight: 600; }
.field-hint       { color: var(--p-text-muted-color); font-size: .78rem; }

/* Mismo tratamiento que los errores de formulario de Usuarios y Campañas. */
.form-error {
    margin-top: 4px;
    padding: 10px 14px;
    background: #fef2f2;
    border-radius: 8px;
    color: #dc2626;
    font-size: .85rem;
    line-height: 1.45;
}

@media (max-width: 640px) {
    .filter-row { flex-wrap: wrap; }
}
</style>
