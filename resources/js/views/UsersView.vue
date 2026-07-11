<template>
    <div class="users">

        <div class="page-header">
            <Button label="Nuevo usuario" icon="pi pi-user-plus" @click="openNewModal" />
        </div>

        <Card>
            <template #title>Usuarios del sistema</template>
            <template #content>
                <div class="table-scroll">
                <DataTable :value="users" :loading="loading" size="small" stripedRows>
                    <Column field="name" header="Nombre" />
                    <Column field="email" header="Correo" />
                    <Column header="Rol">
                        <template #body="{ data }">
                            <Tag :value="roleLabel(data.role)" :severity="roleSeverity(data.role)" />
                        </template>
                    </Column>
                    <Column header="Estado">
                        <template #body="{ data }">
                            <Tag :value="data.is_active ? 'Activo' : 'Inactivo'"
                                 :severity="data.is_active ? 'success' : 'secondary'" />
                        </template>
                    </Column>
                    <Column header="Creado">
                        <template #body="{ data }">
                            <span class="date-cell">{{ data.created_at?.substring(0, 10) }}</span>
                        </template>
                    </Column>
                    <Column header="">
                        <template #body="{ data }">
                            <div class="action-row" v-if="data.id !== currentUser?.id">
                                <Button
                                    label="Contraseña"
                                    icon="pi pi-key"
                                    severity="secondary"
                                    size="small"
                                    text
                                    @click="openPwdModal(data)"
                                />
                                <Button
                                    :label="data.is_active ? 'Desactivar' : 'Activar'"
                                    :severity="data.is_active ? 'warn' : 'success'"
                                    size="small"
                                    text
                                    @click="toggleActive(data)"
                                />
                                <Button
                                    label="Eliminar"
                                    severity="danger"
                                    size="small"
                                    text
                                    @click="confirmDelete(data)"
                                />
                            </div>
                            <span v-else class="you-badge">Tú</span>
                        </template>
                    </Column>
                    <template #empty>
                        <span class="empty-msg">Sin usuarios.</span>
                    </template>
                </DataTable>
                </div>
            </template>
        </Card>

        <!-- ── Modal nuevo usuario ─────────────────────── -->
        <Dialog v-model:visible="showModal" header="Nuevo usuario" modal :style="{ width: '440px' }">
            <div class="modal-form">
                <div class="field">
                    <label>Nombre completo</label>
                    <InputText v-model="form.name" placeholder="Ana García" fluid />
                </div>
                <div class="field">
                    <label>Correo electrónico</label>
                    <InputText v-model="form.email" type="email" placeholder="ana@prestamaz.mx" fluid />
                </div>
                <div class="field">
                    <label>Contraseña</label>
                    <Password v-model="form.password" :feedback="false" toggleMask fluid />
                </div>
                <div class="field">
                    <label>Rol</label>
                    <Select
                        v-model="form.role"
                        :options="roleOptions"
                        option-label="label"
                        option-value="value"
                        fluid
                    />
                    <small class="role-hint">{{ roleHint }}</small>
                </div>
                <div v-if="formError" class="form-error">{{ formError }}</div>
            </div>
            <template #footer>
                <Button label="Cancelar" severity="secondary" text @click="showModal = false" />
                <Button label="Crear usuario" icon="pi pi-check" :loading="saving" :disabled="!canSave" @click="saveUser" />
            </template>
        </Dialog>

        <!-- ── Modal restablecer contraseña ────────────── -->
        <Dialog v-model:visible="showPwdModal" header="Restablecer contraseña" modal :style="{ width: '440px' }">
            <div class="modal-form">
                <p class="pwd-target">
                    Nueva contraseña para <strong>{{ pwdForm.name }}</strong>.
                    La contraseña anterior deja de funcionar de inmediato.
                </p>
                <div class="field">
                    <label>Nueva contraseña</label>
                    <Password v-model="pwdForm.password" :feedback="false" toggleMask fluid />
                    <small class="role-hint">Mínimo 8 caracteres.</small>
                </div>
                <div v-if="pwdError" class="form-error">{{ pwdError }}</div>
            </div>
            <template #footer>
                <Button label="Cancelar" severity="secondary" text @click="showPwdModal = false" />
                <Button label="Guardar contraseña" icon="pi pi-check" :loading="pwdSaving" :disabled="!canSavePwd" @click="resetPassword" />
            </template>
        </Dialog>

    </div>

    <ConfirmDialog />
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { useToast }   from 'primevue/usetoast';
import Card          from 'primevue/card';
import Button        from 'primevue/button';
import DataTable     from 'primevue/datatable';
import Column        from 'primevue/column';
import Tag           from 'primevue/tag';
import Dialog        from 'primevue/dialog';
import InputText     from 'primevue/inputtext';
import Password      from 'primevue/password';
import Select        from 'primevue/select';
import ConfirmDialog from 'primevue/confirmdialog';
import { api }       from '../api.js';
import { useAuth }   from '../auth.js';

const confirm = useConfirm();
const toast   = useToast();
const { user: authState } = useAuth();

const users      = ref([]);
const loading    = ref(false);
const showModal  = ref(false);
const saving     = ref(false);
const formError  = ref('');

const showPwdModal = ref(false);
const pwdSaving    = ref(false);
const pwdError     = ref('');
const pwdForm      = ref({ id: null, name: '', password: '' });

const canSavePwd = computed(() => pwdForm.value.password.length >= 8);

const currentUser = computed(() => authState.user);

const form = ref({ name: '', email: '', password: '', role: 'operator' });

const roleOptions = [
    { label: 'Administrador - acceso completo',               value: 'admin'    },
    { label: 'Operador - contactos y campañas',               value: 'operator' },
    { label: 'Agente - solo atención de mensajes entrantes',  value: 'agent'    },
];

const roleHints = {
    admin    : 'Puede gestionar usuarios, configuración y todo el panel.',
    operator : 'Puede subir contactos, crear y ejecutar campañas. No ve configuración.',
    agent    : 'Solo accede a la bandeja de mensajes entrantes (disponible en Stage 3).',
};

const roleHint = computed(() => roleHints[form.value.role] ?? '');

const canSave = computed(() =>
    form.value.name.trim() &&
    form.value.email.trim() &&
    form.value.password.length >= 8 &&
    form.value.role
);

const roleLabel = (r) => ({ admin: 'Admin', operator: 'Operador', agent: 'Agente' }[r] ?? r);
const roleSeverity = (r) => ({ admin: 'danger', operator: 'info', agent: 'secondary' }[r] ?? 'secondary');

async function loadUsers() {
    loading.value = true;
    const res     = await api.users();
    users.value   = res.data ?? [];
    loading.value = false;
}

function openNewModal() {
    form.value    = { name: '', email: '', password: '', role: 'operator' };
    formError.value = '';
    showModal.value = true;
}

async function saveUser() {
    if (!canSave.value) return;
    saving.value    = true;
    formError.value = '';

    const res = await api.createUser({
        name    : form.value.name.trim(),
        email   : form.value.email.trim(),
        password: form.value.password,
        role    : form.value.role,
    });

    saving.value = false;

    if (res.status === 'ok') {
        showModal.value = false;
        toast.add({ severity: 'success', summary: 'Usuario creado', detail: res.data?.name, life: 3000 });
        await loadUsers();
    } else {
        formError.value = res.message ?? 'Error al crear el usuario.';
    }
}

function openPwdModal(user) {
    pwdForm.value  = { id: user.id, name: user.name, password: '' };
    pwdError.value = '';
    showPwdModal.value = true;
}

async function resetPassword() {
    if (!canSavePwd.value) return;
    pwdSaving.value = true;
    pwdError.value  = '';

    const res = await api.updateUser(pwdForm.value.id, { password: pwdForm.value.password });

    pwdSaving.value = false;

    if (res.status === 'ok') {
        showPwdModal.value = false;
        toast.add({ severity: 'success', summary: 'Contraseña actualizada', detail: pwdForm.value.name, life: 3000 });
    } else {
        pwdError.value = res.message ?? 'No se pudo actualizar la contraseña.';
    }
}

async function toggleActive(user) {
    const res = await api.updateUser(user.id, { is_active: !user.is_active });
    if (res.status === 'ok') {
        await loadUsers();
    } else {
        toast.add({ severity: 'error', summary: 'Error', detail: res.message, life: 4000 });
    }
}

function confirmDelete(user) {
    confirm.require({
        message    : `¿Eliminar al usuario "${user.name}"? Esta acción no se puede deshacer.`,
        header     : 'Confirmar eliminación',
        icon       : 'pi pi-trash',
        acceptLabel: 'Sí, eliminar',
        rejectLabel: 'Cancelar',
        acceptClass: 'p-button-danger',
        accept: async () => {
            const res = await api.deleteUser(user.id);
            if (res.status === 'ok') {
                toast.add({ severity: 'success', summary: 'Usuario eliminado', life: 3000 });
                await loadUsers();
            } else {
                toast.add({ severity: 'error', summary: 'Error', detail: res.message, life: 4000 });
            }
        },
    });
}

onMounted(() => loadUsers());
</script>

<style scoped>
.table-scroll { overflow-x: auto; }
.page-header  { display: flex; justify-content: flex-end; margin-bottom: 16px; }
.date-cell    { color: var(--p-text-muted-color); font-size: .82rem; }
.empty-msg    { color: var(--p-text-muted-color); font-size: .85rem; }
.action-row   { display: flex; gap: 4px; }
.you-badge    { font-size: .78rem; color: var(--p-text-muted-color); font-style: italic; }

.modal-form   { display: flex; flex-direction: column; gap: 14px; }
.field        { display: flex; flex-direction: column; gap: 6px; }
.field label  { font-size: .85rem; font-weight: 600; color: #374151; }
.role-hint    { color: var(--p-text-muted-color); font-size: .78rem; line-height: 1.4; }
.pwd-target   { margin: 0; font-size: .88rem; color: var(--p-text-muted-color); line-height: 1.5; }

.form-error {
    padding: 10px 14px;
    background: #fef2f2;
    border-radius: 8px;
    color: #dc2626;
    font-size: .85rem;
}
</style>
