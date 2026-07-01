<template>
    <div class="contacts">
        <!-- Stats -->
        <div class="stats-row" v-if="contactStats">
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num">{{ contactStats.total }}</span>
                        <span class="stat-lbl">Total</span>
                    </div>
                </template>
            </Card>
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num active">{{ contactStats.active }}</span>
                        <span class="stat-lbl">Activos</span>
                    </div>
                </template>
            </Card>
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num opted">{{ contactStats.opted_out }}</span>
                        <span class="stat-lbl">Opt-out</span>
                    </div>
                </template>
            </Card>
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num invalid">{{ contactStats.invalid }}</span>
                        <span class="stat-lbl">Inválidos</span>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Acciones -->
        <div class="export-row mb-4">
            <Button label="Agregar contacto" icon="pi pi-plus" @click="openAdd" />
            <Button label="Exportar contactos (.xlsx)" icon="pi pi-download" severity="secondary" @click="downloadExport('contacts')" />
        </div>

        <!-- Upload -->
        <Card class="mb-4">
            <template #title>Cargar contactos desde Excel / CSV</template>
            <template #content>
                <p class="upload-hint">
                    <strong>Columna A</strong> = teléfono &nbsp;·&nbsp; <strong>Columna B</strong> = nombre (opcional)<br>
                    Formatos: .xlsx, .xls, .csv — máx. 10 MB. Los números se normalizan al formato mexicano (52 + 10 dígitos).
                </p>
                <div class="upload-row">
                    <input type="file" ref="fileInput" accept=".xlsx,.xls,.csv" @change="onFileChange" class="file-input" />
                    <Button
                        label="Subir y procesar"
                        icon="pi pi-upload"
                        :loading="uploading"
                        :disabled="!uploadFile"
                        @click="uploadContacts"
                    />
                </div>
                <div v-if="uploadResult" class="upload-result" :class="{ 'has-errors': uploadResult.summary?.errors?.length }">
                    <strong>Resultado:</strong>
                    {{ uploadResult.summary?.inserted ?? 0 }} nuevos ·
                    {{ uploadResult.summary?.duplicates ?? 0 }} duplicados ·
                    {{ uploadResult.summary?.invalid ?? 0 }} inválidos
                    (de {{ uploadResult.summary?.total ?? 0 }} filas)
                    <div v-if="uploadResult.error" class="upload-error">{{ uploadResult.error }}</div>
                    <ul v-if="uploadResult.summary?.errors?.length" class="error-list">
                        <li v-for="err in uploadResult.summary.errors" :key="err">{{ err }}</li>
                    </ul>
                </div>
            </template>
        </Card>

        <!-- Tabla -->
        <Card>
            <template #title>
                <span class="table-title">
                    Lista de contactos
                    <i class="pi pi-question-circle title-help" v-tooltip.top="tableHelp"></i>
                </span>
            </template>
            <template #content>
                <div class="filter-row">
                    <InputText v-model="search" placeholder="Buscar teléfono o nombre..." @keyup.enter="loadContacts(1)" fluid />
                    <Select v-model="filter" :options="filterOptions" option-label="label" option-value="value" placeholder="Todos" @change="loadContacts(1)" />
                    <Select v-model="tagFilter" :options="tagFilterOptions" option-label="label" option-value="value" placeholder="Todos los tags" @change="loadContacts(1)" style="min-width: 160px" />
                    <Button icon="pi pi-search" severity="secondary" @click="loadContacts(1)" />
                </div>

                <!-- Barra de acción masiva (aparece al seleccionar contactos) -->
                <div v-if="selected.length" class="bulk-bar">
                    <span class="bulk-count">{{ selected.length }} seleccionado{{ selected.length === 1 ? '' : 's' }}</span>
                    <Select
                        v-model="bulkTagId"
                        :options="allTags"
                        option-label="name"
                        option-value="id"
                        placeholder="Elegir tag..."
                        style="min-width: 180px"
                    />
                    <template v-if="!showBulkNewTag">
                        <Button label="Nuevo tag" icon="pi pi-plus" size="small" severity="secondary" text @click="showBulkNewTag = true" />
                    </template>
                    <template v-else>
                        <InputText v-model="bulkNewTagName" placeholder="Nombre del tag..." @keyup.enter="createBulkTag" style="width: 160px" autofocus />
                        <Button label="Crear" size="small" severity="secondary" :loading="creatingBulkTag" @click="createBulkTag" />
                        <Button icon="pi pi-times" text size="small" severity="secondary" @click="showBulkNewTag = false; bulkNewTagName = ''" />
                    </template>
                    <Button
                        label="Asignar tag"
                        icon="pi pi-tag"
                        size="small"
                        :disabled="!bulkTagId || bulkBusy"
                        :loading="bulkAction === 'attach'"
                        @click="bulkTagAction('attach')"
                    />
                    <Button
                        label="Quitar tag"
                        icon="pi pi-minus-circle"
                        size="small"
                        severity="danger"
                        text
                        :disabled="!bulkTagId || bulkBusy"
                        :loading="bulkAction === 'detach'"
                        @click="bulkTagAction('detach')"
                    />
                    <Button label="Limpiar" text size="small" severity="secondary" @click="selected = []" />
                </div>

                <DataTable v-model:selection="selected" data-key="id" :value="contacts" :loading="loading" size="small" stripedRows class="mt-3">
                    <Column selection-mode="multiple" header-style="width: 3rem" />
                    <Column field="id" header="#" style="width: 60px" />
                    <Column field="phone" header="Teléfono">
                        <template #body="{ data }">
                            <code>{{ data.phone }}</code>
                        </template>
                    </Column>
                    <Column field="name" header="Nombre">
                        <template #body="{ data }">{{ data.name ?? '—' }}</template>
                    </Column>
                    <Column header="Estado">
                        <template #body="{ data }">
                            <Tag
                                :value="statusLabel(data.status)"
                                :severity="statusSeverity(data.status)"
                                v-tooltip.top="data.status === 'opted_out' ? optOutTooltip(data) : null"
                                :style="data.status === 'opted_out' ? 'cursor:help' : ''"
                            />
                        </template>
                    </Column>
                    <Column header="Entregabilidad" style="min-width: 150px">
                        <template #body="{ data }">
                            <Tag
                                :value="deliverLabel(data)"
                                :severity="deliverSeverity(data)"
                                v-tooltip.top="deliverTooltip(data)"
                                :style="deliverTooltip(data) ? 'cursor:help' : ''"
                            />
                        </template>
                    </Column>
                    <Column header="Tags" style="min-width: 140px">
                        <template #body="{ data }">
                            <div class="tag-chips">
                                <span v-for="t in data.tags" :key="t.id" class="tag-chip">{{ t.name }}</span>
                                <span v-if="!data.tags?.length" class="tag-empty">—</span>
                            </div>
                        </template>
                    </Column>
                    <Column field="source" header="Fuente" />
                    <Column header="Registrado">
                        <template #body="{ data }">
                            <span class="date-cell">{{ data.created_at?.substring(0, 10) }}</span>
                        </template>
                    </Column>
                    <Column header="" style="width: 140px">
                        <template #body="{ data }">
                            <div class="row-actions">
                                <Button
                                    icon="pi pi-tag"
                                    text
                                    size="small"
                                    severity="secondary"
                                    @click="openTags(data)"
                                    title="Asignar tags"
                                />
                                <Button
                                    v-if="isAdmin"
                                    icon="pi pi-pencil"
                                    severity="secondary"
                                    size="small"
                                    text
                                    @click="openEdit(data)"
                                />
                                <Button
                                    v-if="data.status === 'active'"
                                    label="Opt-out"
                                    severity="danger"
                                    size="small"
                                    text
                                    @click="confirmOptOut(data)"
                                />
                            </div>
                        </template>
                    </Column>
                    <template #empty>
                        <span class="empty-msg">Sin contactos</span>
                    </template>
                </DataTable>

                <!-- Paginación -->
                <div class="pagination" v-if="meta">
                    <Button icon="pi pi-chevron-left" text severity="secondary" :disabled="meta.current_page <= 1" @click="loadContacts(meta.current_page - 1)" />
                    <span>Página {{ meta.current_page }} de {{ meta.last_page }}</span>
                    <Button icon="pi pi-chevron-right" text severity="secondary" :disabled="meta.current_page >= meta.last_page" @click="loadContacts(meta.current_page + 1)" />
                    <span class="total-count">{{ meta.total }} contactos</span>
                </div>
            </template>
        </Card>
    </div>

    <ConfirmDialog />

    <!-- Dialog asignar tags a contacto -->
    <Dialog v-model:visible="tagsDialog" header="Asignar tags" modal style="width: 420px">
        <p class="tags-dialog-contact">Contacto: <strong>{{ tagsContact?.phone }}</strong></p>
        <MultiSelect
            v-model="selectedTagIds"
            :options="allTags"
            option-label="name"
            option-value="id"
            placeholder="Seleccionar tags..."
            display="chip"
            :show-toggle-all="false"
            fluid
        />
        <div class="tags-manage-row">
            <InputText v-model="newTagName" placeholder="Nuevo tag..." @keyup.enter="createTag" style="flex:1" />
            <Button label="Crear" size="small" severity="secondary" :loading="creatingTag" @click="createTag" />
        </div>
        <div class="tags-list-manage">
            <div v-for="t in allTags" :key="t.id" class="tag-manage-item">
                <span class="tag-chip">{{ t.name }}</span>
                <span class="tag-count">{{ t.contacts_count ?? 0 }} contactos</span>
                <Button icon="pi pi-trash" text severity="danger" size="small" @click="deleteTag(t)" />
            </div>
            <p v-if="!allTags.length" class="tags-empty-hint">No hay tags creados aún.</p>
        </div>
        <template #footer>
            <Button label="Cancelar" text @click="tagsDialog = false" />
            <Button label="Guardar" :loading="savingTags" @click="saveTags" />
        </template>
    </Dialog>

    <!-- Dialog editar contacto (solo admin) -->
    <Dialog v-model:visible="editDialog" header="Editar contacto" modal style="width: 360px">
        <div class="edit-field">
            <label>Teléfono</label>
            <InputText :value="editContact.phone" disabled fluid />
        </div>
        <div class="edit-field">
            <label>Nombre</label>
            <InputText v-model="editContact.name" placeholder="Nombre del contacto" fluid autofocus />
        </div>
        <template #footer>
            <Button label="Cancelar" text @click="editDialog = false" />
            <Button label="Guardar" :loading="saving" @click="saveEdit" />
        </template>
    </Dialog>

    <!-- Dialog agregar contacto manual -->
    <Dialog v-model:visible="addDialog" header="Agregar contacto" modal style="width: 400px">
        <div class="edit-field">
            <label>Teléfono</label>
            <InputText
                v-model="addForm.phone"
                placeholder="529231311146"
                @input="onAddPhoneInput"
                fluid
                autofocus
            />
        </div>

        <!-- Aviso de estado del número (chequeo en vivo) -->
        <div v-if="checkLoading" class="check-note check-muted">
            <i class="pi pi-spin pi-spinner"></i> Verificando número...
        </div>
        <div v-else-if="checkResult && !checkResult.valid_format && addForm.phone" class="check-note check-bad">
            <i class="pi pi-times-circle"></i> Formato inválido (México: 52 + 10 dígitos).
        </div>
        <div v-else-if="checkResult?.exists" class="check-note check-bad">
            <i class="pi pi-exclamation-triangle"></i>
            Ya existe — <strong>{{ statusLabel(checkResult.contact_status) }}</strong>. No se puede agregar.
        </div>
        <div v-else-if="checkResult?.valid_format" class="check-note check-good">
            <i class="pi pi-check-circle"></i> Número disponible para agregar.
        </div>

        <div class="edit-field">
            <label>Nombre <span class="optional">(opcional)</span></label>
            <InputText v-model="addForm.name" placeholder="Nombre del contacto" fluid />
        </div>

        <template #footer>
            <Button label="Cancelar" text @click="addDialog = false" />
            <Button label="Guardar" :loading="savingAdd" :disabled="!canAdd" @click="saveAdd" />
        </template>
    </Dialog>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { useToast }   from 'primevue/usetoast';
import { useAuth }    from '../auth.js';
import Card          from 'primevue/card';
import Button        from 'primevue/button';
import InputText     from 'primevue/inputtext';
import Select        from 'primevue/select';
import MultiSelect   from 'primevue/multiselect';
import DataTable     from 'primevue/datatable';
import Column        from 'primevue/column';
import Tag           from 'primevue/tag';
import ConfirmDialog from 'primevue/confirmdialog';
import Dialog        from 'primevue/dialog';
import { api }       from '../api.js';

const confirm = useConfirm();
const toast   = useToast();
const { user: authState } = useAuth();
const isAdmin = computed(() => authState.user?.role === 'admin');

// Selección masiva de tags
const selected       = ref([]);
const bulkTagId      = ref(null);
const bulkAction     = ref(null); // 'attach' | 'detach' | null — controla el spinner por botón
const showBulkNewTag = ref(false);
const bulkNewTagName = ref('');
const creatingBulkTag = ref(false);

const bulkBusy = computed(() => bulkAction.value !== null);

const contacts     = ref([]);
const meta         = ref(null);
const contactStats = ref(null);
const search       = ref('');
const filter       = ref('');
const tagFilter    = ref(null);
const loading      = ref(false);
const uploadFile   = ref(null);
const uploading    = ref(false);
const uploadResult = ref(null);
const fileInput    = ref(null);
const editDialog   = ref(false);
const editContact  = ref({ id: null, phone: '', name: '' });
const saving       = ref(false);

// Alta individual de contacto
const addDialog    = ref(false);
const addForm      = ref({ phone: '', name: '' });
const savingAdd    = ref(false);
const checkResult  = ref(null);
const checkLoading = ref(false);
let   checkTimer   = null;

const canAdd = computed(() =>
    !!checkResult.value && checkResult.value.valid_format && !checkResult.value.exists && !savingAdd.value
);

const tagFilterOptions = computed(() => [
    { label: 'Todos los tags', value: null },
    ...allTags.value.map(t => ({ label: t.name, value: t.id })),
]);

// Tags
const allTags       = ref([]);
const tagsDialog    = ref(false);
const tagsContact   = ref(null);
const selectedTagIds = ref([]);
const savingTags    = ref(false);
const newTagName    = ref('');
const creatingTag   = ref(false);

const filterOptions = [
    { label: 'Todos',     value: '' },
    { label: 'Activos',   value: 'active' },
    { label: 'Opt-out',   value: 'opted_out' },
    { label: 'Inválidos', value: 'invalid' },
];

const statusLabel = (status) => ({
    active      : 'Activo',
    opted_out   : 'Opt-out',
    invalid     : 'Inválido',
    unreachable : 'Inalcanzable',
}[status] ?? status);

const statusSeverity = (status) => ({
    active      : 'success',
    opted_out   : 'danger',
    invalid     : 'warn',
    unreachable : 'contrast',
}[status] ?? 'secondary');

// Entregabilidad (¿le llega ahora?) — eje distinto al estado de identidad
const isBlockedStatus = (s) => ['opted_out', 'invalid', 'unreachable'].includes(s);

// Precedencia igual que el job de envío: bloqueado → snooze → enviado hoy → cooldown → disponible
const deliverLabel = (c) => {
    if (isBlockedStatus(c.status)) return 'No recibe';
    if (c.snooze_active)   return 'En snooze';
    if (c.sent_today)      return 'Enviado hoy';
    if (c.cooldown_active) return 'En cooldown';
    return 'Disponible';
};

const deliverSeverity = (c) => {
    if (isBlockedStatus(c.status)) return 'danger';
    if (c.snooze_active)   return 'secondary';
    if (c.sent_today)      return 'info';
    if (c.cooldown_active) return 'warn';
    return 'success';
};

const deliverTooltip = (c) => {
    if (isBlockedStatus(c.status)) return 'Bloqueado — no se le envía por ningún medio';
    if (c.snooze_active)   return `En snooze${c.snooze_until ? ` hasta ${c.snooze_until}` : ''} — el contacto pidió "No por ahora"`;
    if (c.sent_today)      return 'Ya recibió un mensaje hoy (no se le reenvía el mismo día)';
    if (c.cooldown_active) return `En cooldown${c.cooldown_until ? ` hasta ${c.cooldown_until}` : ''} — no se le envía hasta que pase`;
    return null;
};

const tableHelp =
    'Estado: identidad del contacto (Activo / Opt-out / Inválido / Inalcanzable). '
    + 'Entregabilidad: si le llega ahora — Disponible, En snooze (pidió "No por ahora"), '
    + 'En cooldown (recibió hace poco), Enviado hoy (ya recibió hoy) o No recibe (bloqueado).';

const optOutTooltip = (contact) => {
    const source = contact.opted_out_source === 'auto'
        ? 'Automático — el contacto respondió para darse de baja'
        : contact.opted_out_source === 'manual'
            ? 'Manual — marcado por un operador'
            : 'Origen desconocido';
    const date = contact.opted_out_at
        ? new Date(contact.opted_out_at).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' })
        : '—';
    return `Baja el: ${date}\nOrigen: ${source}`;
};

async function loadContacts(page = 1) {
    loading.value = true;
    const params = { page };
    if (filter.value)    params.status = filter.value;
    if (search.value)    params.q      = search.value;
    if (tagFilter.value) params.tag_id = tagFilter.value;

    const data      = await api.contacts(params);
    contacts.value  = data.data ?? [];
    meta.value      = data;
    loading.value   = false;
    loadStats();
}

async function loadStats() {
    contactStats.value = await api.contactStats();
}

function openAdd() {
    addForm.value     = { phone: '', name: '' };
    checkResult.value = null;
    checkLoading.value = false;
    addDialog.value   = true;
}

// Chequeo de entregabilidad con debounce mientras el operador teclea el número.
function onAddPhoneInput() {
    clearTimeout(checkTimer);
    checkResult.value = null;
    const phone = addForm.value.phone.trim();
    if (!phone) { checkLoading.value = false; return; }

    checkLoading.value = true;
    checkTimer = setTimeout(async () => {
        const res = await api.checkContact(phone);
        // Evitar carrera: ignorar si el input cambió mientras llegaba la respuesta
        if (addForm.value.phone.trim() !== phone) return;
        checkResult.value  = res.status === 'ok' ? res.data : null;
        checkLoading.value = false;
    }, 400);
}

async function saveAdd() {
    if (!canAdd.value) return;
    savingAdd.value = true;
    const res = await api.createContact({
        phone: addForm.value.phone.trim(),
        name : addForm.value.name.trim() || null,
    });
    savingAdd.value = false;

    if (res.status === 'ok') {
        toast.add({ severity: 'success', summary: 'Contacto agregado', detail: res.data.phone, life: 3000 });
        addDialog.value = false;
        await loadContacts(1);
    } else {
        toast.add({ severity: 'error', summary: 'No se pudo agregar', detail: res.message, life: 5000 });
        // Si el backend devolvió el estado del duplicado, reflejarlo en el aviso
        if (res.data) checkResult.value = res.data;
    }
}

function onFileChange(e) {
    uploadFile.value   = e.target.files[0] ?? null;
    uploadResult.value = null;
}

async function uploadContacts() {
    if (!uploadFile.value) return;
    uploading.value    = true;
    uploadResult.value = null;

    uploadResult.value = await api.uploadContacts(uploadFile.value);
    uploading.value    = false;
    fileInput.value.value = '';
    uploadFile.value   = null;

    if (uploadResult.value.success) await loadContacts(1);
}

function openEdit(contact) {
    editContact.value = { id: contact.id, phone: contact.phone, name: contact.name ?? '' };
    editDialog.value  = true;
}

async function saveEdit() {
    saving.value = true;
    await api.updateContact(editContact.value.id, { name: editContact.value.name });
    editDialog.value = false;
    saving.value     = false;
    await loadContacts(meta.value?.current_page ?? 1);
}

function confirmOptOut(contact) {
    confirm.require({
        message : `¿Marcar ${contact.phone} como opt-out? Esta acción no se puede deshacer.`,
        header  : 'Confirmar opt-out',
        icon    : 'pi pi-exclamation-triangle',
        acceptLabel: 'Sí, dar de baja',
        rejectLabel: 'Cancelar',
        acceptClass: 'p-button-danger',
        accept: async () => {
            await api.optOutContact(contact.id);
            await loadContacts(meta.value?.current_page ?? 1);
        },
    });
}

async function downloadExport(type) {
    const token = localStorage.getItem('wa_token');
    const res   = await fetch(`/api/export/${type}`, {
        headers: token ? { 'Authorization': `Bearer ${token}` } : {},
    });
    if (!res.ok) return;
    const blob     = await res.blob();
    const url      = URL.createObjectURL(blob);
    const filename = res.headers.get('content-disposition')?.match(/filename="(.+)"/)?.[1]
                     ?? `${type}_export.xlsx`;
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

async function loadTags() {
    const res = await api.tags();
    if (res.status === 'ok') allTags.value = res.data;
}

function openTags(contact) {
    tagsContact.value  = contact;
    selectedTagIds.value = (contact.tags ?? []).map(t => t.id);
    tagsDialog.value   = true;
}

async function saveTags() {
    savingTags.value = true;
    const res = await api.syncContactTags(tagsContact.value.id, selectedTagIds.value);
    if (res.status === 'ok') {
        const c = contacts.value.find(c => c.id === tagsContact.value.id);
        if (c) c.tags = res.data;
        tagsDialog.value = false;
    }
    savingTags.value = false;
}

async function createBulkTag() {
    if (!bulkNewTagName.value.trim()) return;
    creatingBulkTag.value = true;
    const res = await api.createTag(bulkNewTagName.value.trim());
    creatingBulkTag.value = false;

    if (res.status === 'ok') {
        allTags.value.push(res.data);
        bulkTagId.value      = res.data.id; // auto-selecciona el tag recién creado
        showBulkNewTag.value = false;
        bulkNewTagName.value = '';
    } else {
        toast.add({ severity: 'error', summary: 'Error', detail: res.message ?? 'No se pudo crear el tag.', life: 4000 });
    }
}

async function bulkTagAction(action) {
    if (!bulkTagId.value || !selected.value.length || bulkBusy.value) return;
    bulkAction.value = action;
    const ids     = selected.value.map(c => c.id);
    const res     = action === 'attach'
        ? await api.bulkAttachTag(ids, bulkTagId.value)
        : await api.bulkDetachTag(ids, bulkTagId.value);
    bulkAction.value = null;

    if (res.status === 'ok') {
        const tagName = allTags.value.find(t => t.id === bulkTagId.value)?.name ?? '';
        const count   = action === 'attach' ? res.data.attached : res.data.detached;
        toast.add({
            severity: 'success',
            summary : action === 'attach' ? 'Tag asignado' : 'Tag quitado',
            detail  : `"${tagName}" ${action === 'attach' ? 'asignado a' : 'quitado de'} ${count} contacto(s).`,
            life    : 3000,
        });
        selected.value       = [];
        bulkTagId.value      = null;
        showBulkNewTag.value = false;
        bulkNewTagName.value = '';
        await loadContacts(meta.value?.current_page ?? 1);
        await loadTags();
    } else {
        toast.add({ severity: 'error', summary: 'Error', detail: res.message, life: 5000 });
    }
}

async function createTag() {
    if (!newTagName.value.trim()) return;
    creatingTag.value = true;
    const res = await api.createTag(newTagName.value.trim());
    if (res.status === 'ok') {
        allTags.value.push(res.data);
        newTagName.value = '';
    }
    creatingTag.value = false;
}

async function deleteTag(tag) {
    await api.deleteTag(tag.id);
    allTags.value = allTags.value.filter(t => t.id !== tag.id);
    selectedTagIds.value = selectedTagIds.value.filter(id => id !== tag.id);
}

onMounted(() => { loadContacts(); loadTags(); });
</script>

<style scoped>
.stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 20px;
}

.stat { text-align: center; }
.stat-num         { display: block; font-size: 2rem; font-weight: 700; }
.stat-num.active  { color: var(--p-green-500); }
.stat-num.opted   { color: var(--p-red-500); }
.stat-num.invalid { color: var(--p-orange-500); }
.stat-lbl { display: block; font-size: .75rem; color: var(--p-text-muted-color); margin-top: 4px; }

.upload-hint { font-size: .82rem; color: var(--p-text-muted-color); margin-bottom: 12px; line-height: 1.6; }
.upload-row  { display: flex; gap: 12px; align-items: center; margin-bottom: 10px; }
.file-input  { flex: 1; }

.upload-result {
    padding: 12px 16px;
    background: var(--p-green-50);
    border-radius: 8px;
    font-size: .85rem;
    margin-top: 10px;
}
.upload-result.has-errors { background: var(--p-orange-50); }
.upload-error  { color: var(--p-red-600); margin-top: 4px; }
.error-list    { margin-top: 8px; padding-left: 16px; font-size: .8rem; }

.table-title   { display: inline-flex; align-items: center; gap: 8px; }
.title-help    { font-size: .95rem; color: var(--p-text-muted-color); cursor: help; }

.filter-row    { display: flex; gap: 8px; }

.bulk-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 12px;
    padding: 8px 14px;
    background: var(--p-primary-50);
    border-radius: 8px;
}
.bulk-count { font-size: .85rem; font-weight: 600; color: var(--p-primary-700); }
.mt-3          { margin-top: 12px; }
.mb-4          { margin-bottom: 20px; }
.date-cell     { color: var(--p-text-muted-color); font-size: .82rem; }
.empty-msg     { color: var(--p-text-muted-color); font-size: .85rem; }

.pagination {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 12px;
    font-size: .85rem;
}
.total-count { color: var(--p-text-muted-color); margin-left: 8px; }
.export-row  { display: flex; justify-content: flex-end; }
.row-actions { display: flex; gap: 2px; align-items: center; }

.edit-field        { display: flex; flex-direction: column; gap: 4px; margin-bottom: 14px; }
.edit-field label  { font-size: .85rem; font-weight: 600; }
.optional          { font-weight: 400; color: var(--p-text-muted-color); }

.check-note {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 12px;
    border-radius: 6px;
    font-size: .82rem;
    margin-bottom: 14px;
}
.check-note .pi  { flex-shrink: 0; }
.check-good  { background: var(--p-green-50);  color: var(--p-green-700); }
.check-bad   { background: var(--p-red-50);    color: var(--p-red-700); }
.check-muted { background: var(--p-surface-100); color: var(--p-text-muted-color); }

/* Tags */
.tag-chips  { display: flex; flex-wrap: wrap; gap: 4px; }
.tag-chip   { font-size: .7rem; padding: 2px 8px; border-radius: 20px; background: var(--p-primary-100); color: var(--p-primary-700); white-space: nowrap; }
.tag-empty  { font-size: .78rem; color: var(--p-text-muted-color); }

.tags-dialog-contact { font-size: .85rem; margin-bottom: 12px; }
.tags-manage-row     { display: flex; gap: 8px; margin-top: 14px; }
.tags-list-manage    { margin-top: 12px; display: flex; flex-direction: column; gap: 6px; max-height: 180px; overflow-y: auto; }
.tag-manage-item     { display: flex; align-items: center; gap: 8px; padding: 4px 0; }
.tag-count           { font-size: .75rem; color: var(--p-text-muted-color); flex: 1; }
.tags-empty-hint     { font-size: .82rem; color: var(--p-text-muted-color); text-align: center; }
</style>
