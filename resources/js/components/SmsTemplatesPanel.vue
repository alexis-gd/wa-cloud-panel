<template>
    <div class="sms-tpl">
        <div class="panel-header">
            <p class="panel-sub">
                Plantillas de SMS locales. No pasan por Meta: las creas y se usan de inmediato en
                campanas o pruebas. Incluye siempre una opcion de baja (ej. "Responde STOP para baja").
            </p>
            <Button v-if="isAdmin" label="Nueva plantilla SMS" icon="pi pi-plus" @click="openCreate" />
        </div>

        <div class="main-layout">
            <div class="table-panel">
                <div v-if="loading" class="empty-state">Cargando...</div>
                <div v-else-if="templates.length === 0" class="empty-state">
                    <i class="pi pi-envelope empty-icon"></i>
                    <p class="empty-title">Sin plantillas de SMS.</p>
                    <p v-if="isAdmin" class="empty-sub">Crea una con el boton "Nueva plantilla SMS".</p>
                </div>
                <div v-else class="table-scroll">
                <table class="tpl-table">
                    <thead>
                        <tr><th>Nombre</th><th>Mensaje</th><th class="col-center">Activa</th><th></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="t in templates" :key="t.id"
                            @click="select(t)"
                            :class="['tpl-row', selected?.id === t.id ? 'tpl-row--selected' : '', !t.is_active ? 'tpl-row--inactive' : '']">
                            <td><code class="tpl-name">{{ t.name }}</code></td>
                            <td class="body-cell">{{ t.body }}</td>
                            <td class="col-center">
                                <ToggleSwitch :modelValue="t.is_active" :disabled="!isAdmin" @update:modelValue="toggleActive(t, $event)" @click.stop />
                            </td>
                            <td class="col-right">
                                <Button v-if="isAdmin" icon="pi pi-pencil" text size="small" @click.stop="openEdit(t)" />
                                <Button v-if="isAdmin" icon="pi pi-trash" text severity="danger" size="small" @click.stop="confirmDelete(t)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="preview-panel">
                <p class="preview-label">Vista previa</p>
                <div v-if="!selected" class="preview-empty">
                    <i class="pi pi-eye"></i><span>Selecciona una plantilla</span>
                </div>
                <div v-else class="sms-preview">
                    <div class="sms-bubble">{{ selected.body }}</div>
                    <small class="sms-meta">{{ selected.body.length }} caracteres · {{ segments(selected.body) }} segmento{{ segments(selected.body) === 1 ? '' : 's' }}</small>
                    <Button v-if="isAdmin" label="Enviar prueba" icon="pi pi-send" size="small" severity="secondary" class="test-btn" @click="openTest" />
                </div>
            </div>
        </div>

        <!-- Crear / editar -->
        <Dialog v-model:visible="showForm" :header="editing ? 'Editar plantilla SMS' : 'Nueva plantilla SMS'" modal :style="{ width: '460px' }">
            <div class="form-group">
                <label>Nombre interno</label>
                <InputText v-model="form.name" placeholder="Ej. promo_verano" fluid />
                <small class="hint">Solo para identificarla en el panel. El contacto no lo ve.</small>
            </div>
            <div class="form-group">
                <label>Mensaje</label>
                <Textarea v-model="form.body" rows="4" fluid auto-resize placeholder="Prestamaz: prestamo desde $10,000. Responde STOP para baja." />
                <small class="hint">{{ form.body.length }} caracteres · {{ segments(form.body) }} segmento{{ segments(form.body) === 1 ? '' : 's' }}</small>
            </div>
            <Message v-if="formError" severity="error" class="mt">{{ formError }}</Message>
            <template #footer>
                <Button label="Cancelar" text @click="showForm = false" />
                <Button label="Guardar" icon="pi pi-check" :loading="saving" :disabled="!canSave" @click="save" />
            </template>
        </Dialog>

        <!-- Enviar prueba -->
        <Dialog v-model:visible="showTest" header="Enviar SMS de prueba" modal :style="{ width: '400px' }">
            <div class="form-group">
                <label>Plantilla</label>
                <code class="tpl-name">{{ selected?.name }}</code>
            </div>
            <div class="form-group">
                <label>Numero de prueba (10 digitos)</label>
                <InputText v-model="testNumber" inputmode="numeric" maxlength="10"
                    placeholder="Ej. 9231234567"
                    @input="testNumber = testNumber.replace(/\D/g, '').slice(0, 10)" fluid />
                <small class="hint">El sistema agrega el +52 (Mexico).</small>
            </div>
            <Message v-if="testResult" :severity="testResult.status === 'ok' ? 'success' : 'error'" class="mt">
                {{ testResult.message }}
            </Message>
            <template #footer>
                <Button label="Cancelar" text @click="showTest = false" />
                <Button label="Enviar" icon="pi pi-send" :loading="testSending" :disabled="testNumber.length !== 10" @click="sendTest" />
            </template>
        </Dialog>

        <ConfirmDialog />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useToast }   from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import Button        from 'primevue/button';
import InputText     from 'primevue/inputtext';
import Textarea      from 'primevue/textarea';
import ToggleSwitch  from 'primevue/toggleswitch';
import Dialog        from 'primevue/dialog';
import Message       from 'primevue/message';
import ConfirmDialog from 'primevue/confirmdialog';
import { api }     from '../api.js';
import { useAuth } from '../auth.js';

const toast   = useToast();
const confirm = useConfirm();
const { user: authState } = useAuth();
const isAdmin = computed(() => ['admin', 'superadmin'].includes(authState.user?.role));

const templates = ref([]);
const selected  = ref(null);
const loading   = ref(false);

const showForm  = ref(false);
const editing   = ref(null);
const form      = ref({ name: '', body: '' });
const saving    = ref(false);
const formError = ref('');

const showTest    = ref(false);
const testNumber  = ref('');
const testSending = ref(false);
const testResult  = ref(null);

const canSave = computed(() => form.value.name.trim() !== '' && form.value.body.trim() !== '');

// 160 chars = 1 segmento; luego 153 c/u por overhead UDH.
function segments(text) {
    const len = (text ?? '').length;
    if (len === 0) return 0;
    return len <= 160 ? 1 : Math.ceil(len / 153);
}

onMounted(load);

async function load() {
    loading.value = true;
    const res = await api.smsTemplates();
    if (res.status === 'ok') templates.value = res.data;
    loading.value = false;
}

function select(t) {
    selected.value = selected.value?.id === t.id ? null : t;
}

function openCreate() {
    editing.value = null;
    form.value = { name: '', body: '' };
    formError.value = '';
    showForm.value = true;
}

function openEdit(t) {
    editing.value = t;
    form.value = { name: t.name, body: t.body };
    formError.value = '';
    showForm.value = true;
}

async function save() {
    saving.value = true;
    formError.value = '';
    const payload = { name: form.value.name.trim(), body: form.value.body.trim() };
    const res = editing.value
        ? await api.updateSmsTemplate(editing.value.id, payload)
        : await api.createSmsTemplate(payload);
    saving.value = false;

    if (res.status === 'ok') {
        showForm.value = false;
        toast.add({ severity: 'success', summary: editing.value ? 'Plantilla actualizada' : 'Plantilla creada', life: 3000 });
        await load();
    } else {
        formError.value = res.message ?? 'No se pudo guardar.';
    }
}

async function toggleActive(t, value) {
    const res = await api.updateSmsTemplate(t.id, { is_active: value });
    if (res.status === 'ok') t.is_active = value;
}

function confirmDelete(t) {
    confirm.require({
        message: `¿Eliminar "${t.name}"?`,
        header: 'Eliminar plantilla SMS', icon: 'pi pi-trash',
        acceptLabel: 'Eliminar', rejectLabel: 'Cancelar', acceptClass: 'p-button-danger',
        accept: async () => {
            const res = await api.deleteSmsTemplate(t.id);
            if (res.status === 'ok') {
                templates.value = templates.value.filter(x => x.id !== t.id);
                if (selected.value?.id === t.id) selected.value = null;
                toast.add({ severity: 'success', summary: 'Plantilla eliminada', life: 3000 });
            }
        },
    });
}

function openTest() {
    testNumber.value = '';
    testResult.value = null;
    showTest.value = true;
}

async function sendTest() {
    testSending.value = true;
    testResult.value = null;
    const res = await api.sendSmsTest({ to: testNumber.value, body: selected.value.body });
    testResult.value = { status: res.status, message: res.status === 'ok' ? 'SMS de prueba enviado.' : (res.message ?? 'Error al enviar.') };
    testSending.value = false;
}
</script>

<style scoped>
.sms-tpl       { display: flex; flex-direction: column; gap: 16px; }
.panel-header  { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.panel-sub     { font-size: .82rem; color: var(--p-text-muted-color); margin: 0; max-width: 60ch; }

.main-layout   { display: grid; grid-template-columns: 1fr 280px; gap: 20px; align-items: start; }
.table-scroll  { overflow-x: auto; }
.table-panel   { background: var(--p-content-background); border: 1px solid var(--p-content-border-color); border-radius: 12px; overflow: hidden; }
@media (max-width: 768px) { .main-layout { grid-template-columns: 1fr; } }

.empty-state   { padding: 48px 24px; text-align: center; color: var(--p-text-muted-color); }
.empty-icon    { font-size: 2.5rem; display: block; margin-bottom: 12px; }
.empty-title   { font-weight: 600; margin: 0 0 4px; }
.empty-sub     { font-size: .82rem; margin: 0; }

.tpl-table     { width: 100%; border-collapse: collapse; font-size: .875rem; }
.tpl-table th  { text-align: left; padding: 10px 16px; background: var(--p-surface-50); border-bottom: 1px solid var(--p-content-border-color); font-weight: 600; color: var(--p-text-muted-color); font-size: .8rem; }
.tpl-row       { border-bottom: 1px solid var(--p-content-border-color); cursor: pointer; transition: background .12s; }
.tpl-row:last-child { border-bottom: none; }
.tpl-row:hover      { background: var(--p-surface-50); }
.tpl-row--selected  { background: var(--p-primary-50) !important; }
.tpl-row--inactive  { opacity: .5; }
.tpl-row td         { padding: 10px 16px; }
.body-cell     { color: var(--p-text-muted-color); max-width: 340px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.tpl-name      { background: var(--p-surface-100); padding: 2px 8px; border-radius: 4px; font-size: .75rem; font-family: monospace; }
.col-center    { text-align: center; }
.col-right     { text-align: right; white-space: nowrap; }

.preview-panel { display: flex; flex-direction: column; gap: 10px; }
.preview-label { font-size: .8rem; font-weight: 600; color: var(--p-text-muted-color); margin: 0; }
.preview-empty { background: var(--p-content-background); border: 1px solid var(--p-content-border-color); border-radius: 12px; padding: 40px 16px; text-align: center; color: var(--p-text-muted-color); display: flex; flex-direction: column; align-items: center; gap: 8px; font-size: .85rem; }
.preview-empty .pi { font-size: 2rem; }

.sms-preview   { background: #eef1f4; border-radius: 12px; padding: 16px; display: flex; flex-direction: column; gap: 10px; }
.sms-bubble    { background: #e1e8f0; color: #14223a; border-radius: 14px 14px 14px 4px; padding: 10px 12px; font-size: .82rem; line-height: 1.5; white-space: pre-wrap; word-break: break-word; }
.sms-meta      { color: var(--p-text-muted-color); font-size: .74rem; }
.test-btn      { width: 100%; margin-top: 4px; }

.form-group    { margin-bottom: 14px; }
.form-group label { display: block; font-size: .82rem; color: var(--p-text-muted-color); margin-bottom: 6px; }
.hint          { display: block; margin-top: 6px; font-size: .76rem; color: var(--p-text-muted-color); }
.mt            { margin-top: 10px; }
</style>
