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

        <!-- Exportar -->
        <div class="export-row mb-4">
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
            <template #title>Lista de contactos</template>
            <template #content>
                <div class="filter-row">
                    <InputText v-model="search" placeholder="Buscar teléfono o nombre..." @keyup.enter="loadContacts(1)" fluid />
                    <Select v-model="filter" :options="filterOptions" option-label="label" option-value="value" placeholder="Todos" @change="loadContacts(1)" />
                    <Button icon="pi pi-search" severity="secondary" @click="loadContacts(1)" />
                </div>

                <DataTable :value="contacts" :loading="loading" size="small" stripedRows class="mt-3">
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
                            <Tag :value="data.status" :severity="statusSeverity(data.status)" />
                        </template>
                    </Column>
                    <Column field="source" header="Fuente" />
                    <Column header="Fecha">
                        <template #body="{ data }">
                            <span class="date-cell">{{ data.created_at?.substring(0, 10) }}</span>
                        </template>
                    </Column>
                    <Column header="" style="width: 120px">
                        <template #body="{ data }">
                            <div class="row-actions">
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
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { useAuth }    from '../auth.js';
import Card          from 'primevue/card';
import Button        from 'primevue/button';
import InputText     from 'primevue/inputtext';
import Select        from 'primevue/select';
import DataTable     from 'primevue/datatable';
import Column        from 'primevue/column';
import Tag           from 'primevue/tag';
import ConfirmDialog from 'primevue/confirmdialog';
import Dialog        from 'primevue/dialog';
import { api }       from '../api.js';

const confirm = useConfirm();
const { user: authState } = useAuth();
const isAdmin = computed(() => authState.user?.role === 'admin');

const contacts     = ref([]);
const meta         = ref(null);
const contactStats = ref(null);
const search       = ref('');
const filter       = ref('');
const loading      = ref(false);
const uploadFile   = ref(null);
const uploading    = ref(false);
const uploadResult = ref(null);
const fileInput    = ref(null);
const editDialog   = ref(false);
const editContact  = ref({ id: null, phone: '', name: '' });
const saving       = ref(false);

const filterOptions = [
    { label: 'Todos',     value: '' },
    { label: 'Activos',   value: 'active' },
    { label: 'Opt-out',   value: 'opted_out' },
    { label: 'Inválidos', value: 'invalid' },
];

const statusSeverity = (status) => ({
    active    : 'success',
    opted_out : 'danger',
    invalid   : 'warn',
}[status] ?? 'secondary');

async function loadContacts(page = 1) {
    loading.value = true;
    const params = { page };
    if (filter.value) params.status = filter.value;
    if (search.value) params.q      = search.value;

    const data      = await api.contacts(params);
    contacts.value  = data.data ?? [];
    meta.value      = data;
    loading.value   = false;
    loadStats();
}

async function loadStats() {
    contactStats.value = await api.contactStats();
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

onMounted(() => loadContacts());
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

.filter-row    { display: flex; gap: 8px; }
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
</style>
