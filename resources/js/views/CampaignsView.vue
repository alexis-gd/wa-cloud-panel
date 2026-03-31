<template>
    <div class="campaigns">

        <!-- ── Cabecera con botón nueva campaña ─────── -->
        <div class="page-header">
            <Button label="Nueva campaña" icon="pi pi-plus" @click="openNewModal" />
        </div>

        <!-- ── Tabla de campañas ─────────────────────── -->
        <Card>
            <template #title>Campañas</template>
            <template #content>
                <DataTable :value="campaigns" :loading="loading" size="small" stripedRows>
                    <Column field="id" header="#" style="width:60px" />
                    <Column field="name" header="Nombre" />
                    <Column field="template_name" header="Plantilla">
                        <template #body="{ data }">
                            <code>{{ data.template_name }}</code>
                        </template>
                    </Column>
                    <Column header="Estado">
                        <template #body="{ data }">
                            <Tag :value="statusLabel(data.status)" :severity="statusSeverity(data.status)" />
                        </template>
                    </Column>
                    <Column header="Progreso">
                        <template #body="{ data }">
                            <span class="progress-cell">
                                {{ data.sent_count ?? 0 }} / {{ data.total_contacts ?? 0 }}
                            </span>
                        </template>
                    </Column>
                    <Column header="Fecha">
                        <template #body="{ data }">
                            <span class="date-cell">{{ data.created_at?.substring(0, 10) }}</span>
                        </template>
                    </Column>
                    <Column header="">
                        <template #body="{ data }">
                            <Button
                                v-if="data.status === 'draft' || data.status === 'paused'"
                                label="Ejecutar"
                                icon="pi pi-play"
                                severity="success"
                                size="small"
                                text
                                @click="confirmExecute(data)"
                            />
                        </template>
                    </Column>
                    <template #empty>
                        <span class="empty-msg">Sin campañas. Crea una para comenzar.</span>
                    </template>
                </DataTable>

                <!-- Paginación -->
                <div class="pagination" v-if="meta">
                    <Button icon="pi pi-chevron-left" text severity="secondary"
                        :disabled="meta.page <= 1"
                        @click="loadCampaigns(meta.page - 1)" />
                    <span>Página {{ meta.page }} de {{ Math.ceil(meta.total / meta.per_page) }}</span>
                    <Button icon="pi pi-chevron-right" text severity="secondary"
                        :disabled="meta.page >= Math.ceil(meta.total / meta.per_page)"
                        @click="loadCampaigns(meta.page + 1)" />
                    <span class="total-count">{{ meta.total }} campañas</span>
                </div>
            </template>
        </Card>

        <!-- ── Modal nueva campaña ───────────────────── -->
        <Dialog v-model:visible="showModal" header="Nueva campaña" modal :style="{ width: '480px' }">
            <div class="modal-form">
                <label class="field-label">Nombre de la campaña</label>
                <InputText v-model="form.name" placeholder="Ej: Promo mayo — préstamos personales" fluid />

                <label class="field-label mt">Plantilla</label>
                <Select
                    v-model="form.template"
                    :options="approvedTemplates"
                    option-label="display"
                    placeholder="Selecciona plantilla aprobada"
                    :loading="loadingTemplates"
                    fluid
                />
                <small v-if="approvedTemplates.length === 0 && !loadingTemplates" class="no-templates">
                    No hay plantillas aprobadas. Sube plantillas desde Meta Business Manager.
                </small>

                <template v-if="form.template && varCount > 0">
                    <label class="field-label mt">Variables del mensaje ({{ varCount }} variable{{ varCount > 1 ? 's' : '' }})</label>
                    <div v-for="(varName, i) in templateVarLabels" :key="i" class="var-row">
                        <span class="var-label">{{ varName }}</span>
                        <InputText v-model="form.bodyVars[i]" :placeholder="varName" fluid />
                    </div>
                </template>
                <div v-else-if="form.template && varCount === 0" class="field-label mt">
                    <small class="no-templates">Esta plantilla no tiene variables.</small>
                </div>

                <div v-if="formError" class="form-error">{{ formError }}</div>
            </div>

            <template #footer>
                <Button label="Cancelar" severity="secondary" text @click="showModal = false" />
                <Button label="Crear campaña" icon="pi pi-check" :loading="saving" :disabled="!canSave" @click="saveCampaign" />
            </template>
        </Dialog>

    </div>

    <ConfirmDialog />
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue';
import { useConfirm } from 'primevue/useconfirm';
import { useToast }   from 'primevue/usetoast';
import Card          from 'primevue/card';
import Button        from 'primevue/button';
import DataTable     from 'primevue/datatable';
import Column        from 'primevue/column';
import Tag           from 'primevue/tag';
import Dialog        from 'primevue/dialog';
import InputText     from 'primevue/inputtext';
import Select        from 'primevue/select';
import ConfirmDialog from 'primevue/confirmdialog';
import { api }       from '../api.js';

const confirm = useConfirm();
const toast   = useToast();

const campaigns        = ref([]);
const meta             = ref(null);
const loading          = ref(false);
const approvedTemplates = ref([]);
const loadingTemplates  = ref(false);
const showModal        = ref(false);
const saving           = ref(false);
const formError        = ref('');

const form = ref({ name: '', template: null, bodyVars: [] });

function extractVarLabels(bodyText) {
    if (!bodyText) return [];
    const matches = [...bodyText.matchAll(/\{\{([^}]+)\}\}/g)];
    return matches.map(m => {
        const inner = m[1].trim();
        return /^\d+$/.test(inner) ? `Variable ${inner}` : inner;
    });
}

const templateVarLabels = computed(() => extractVarLabels(form.value.template?.body_text ?? ''));
const varCount = computed(() => templateVarLabels.value.length);

const canSave = computed(() =>
    form.value.name.trim() !== '' && form.value.template !== null
);

watch(() => form.value.template, () => {
    form.value.bodyVars = Array(varCount.value).fill('');
});

const statusLabel = (s) => ({
    draft   : 'Borrador',
    running : 'Ejecutando',
    paused  : 'Pausada',
    done    : 'Finalizada',
}[s] ?? s);

const statusSeverity = (s) => ({
    draft   : 'secondary',
    running : 'info',
    paused  : 'warn',
    done    : 'success',
}[s] ?? 'secondary');

async function loadCampaigns(page = 1) {
    loading.value = true;
    const data       = await api.campaigns({ page });
    campaigns.value  = data.data ?? [];
    meta.value       = data.meta ?? null;
    loading.value    = false;
}

async function openNewModal() {
    form.value    = { name: '', template: null, bodyVars: [] };
    formError.value = '';
    showModal.value = true;

    if (approvedTemplates.value.length === 0) {
        loadingTemplates.value = true;
        const raw = await api.templates();
        const list = Array.isArray(raw) ? raw : (raw.data ?? []);
        approvedTemplates.value = list
            .filter(t => t.status === 'approved' && t.is_active)
            .map(t => ({ ...t, display: `${t.name} (${t.language_code})`, body_text: t.body_text ?? '' }));
        loadingTemplates.value = false;
    }
}

async function saveCampaign() {
    if (!canSave.value) return;
    saving.value    = true;
    formError.value = '';

    const payload = {
        name          : form.value.name.trim(),
        template_name : form.value.template.name,
        language_code : form.value.template.language_code,
        body_vars     : form.value.bodyVars.filter(v => v !== ''),
    };

    const res = await api.createCampaign(payload);
    saving.value = false;

    if (res.status === 'ok') {
        showModal.value = false;
        toast.add({ severity: 'success', summary: 'Campaña creada', detail: res.data?.name, life: 3000 });
        await loadCampaigns(1);
    } else {
        formError.value = res.message ?? 'Error al crear la campaña.';
    }
}

function confirmExecute(campaign) {
    confirm.require({
        message    : `¿Ejecutar la campaña "${campaign.name}"? Se encolará un mensaje para cada contacto activo.`,
        header     : 'Confirmar ejecución',
        icon       : 'pi pi-play-circle',
        acceptLabel: 'Sí, ejecutar',
        rejectLabel: 'Cancelar',
        accept: async () => {
            const res = await api.executeCampaign(campaign.id);
            if (res.status === 'ok') {
                toast.add({
                    severity: 'success',
                    summary : 'Campaña iniciada',
                    detail  : res.data?.message,
                    life    : 4000,
                });
                await loadCampaigns(meta.value?.page ?? 1);
            } else {
                toast.add({ severity: 'error', summary: 'Error', detail: res.message, life: 5000 });
            }
        },
    });
}

onMounted(() => loadCampaigns());
</script>

<style scoped>
.page-header {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 16px;
}

.progress-cell { font-variant-numeric: tabular-nums; font-size: .85rem; }
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

/* Modal form */
.modal-form    { display: flex; flex-direction: column; gap: 6px; }
.field-label   { font-size: .85rem; font-weight: 600; color: var(--p-text-color); }
.field-label.mt { margin-top: 12px; }
.mt            { margin-top: 12px; }

.var-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 6px;
}
.var-label {
    font-family: monospace;
    font-size: .85rem;
    color: var(--p-text-muted-color);
    min-width: 36px;
}

.no-templates {
    color: var(--p-orange-600);
    font-size: .8rem;
    margin-top: 4px;
}

.form-error {
    margin-top: 10px;
    padding: 10px 14px;
    background: var(--p-red-50);
    border-radius: 8px;
    color: var(--p-red-700);
    font-size: .85rem;
}
</style>
