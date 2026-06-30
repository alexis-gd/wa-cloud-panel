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
                    <Column header="Nombre">
                        <template #body="{ data }">
                            <span class="campaign-name-link" @click="openDetail(data)">{{ data.name }}</span>
                        </template>
                    </Column>
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
                    <Column header="Fallidos">
                        <template #body="{ data }">
                            <span v-if="data.failed_count > 0" class="failed-cell">{{ data.failed_count }}</span>
                            <span v-else class="muted-cell">—</span>
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

                <label class="field-label mt">Destinatarios</label>
                <Select
                    v-model="form.tagId"
                    :options="tagOptions"
                    option-label="label"
                    option-value="value"
                    placeholder="Todos los contactos activos"
                    fluid
                />

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
                <Button label="Cancelar" text @click="showModal = false" />
                <Button label="Crear campaña" icon="pi pi-check" :loading="saving" :disabled="!canSave" @click="saveCampaign" />
            </template>
        </Dialog>

        <!-- ── Dialog detalle de campaña ───────────────── -->
        <Dialog
            v-model:visible="showDetail"
            :header="selectedCampaign?.name"
            modal
            :style="{ width: '700px' }"
            @hide="stopDetailPolling"
        >
            <div v-if="selectedCampaign" class="detail-body">

                <!-- Barra de progreso -->
                <div class="progress-bars">
                    <div class="bar-row">
                        <span class="bar-label">Enviados</span>
                        <div class="bar-track">
                            <div
                                class="bar-fill bar-sent"
                                :style="{ width: progressPct(selectedCampaign.sent_count, selectedCampaign.total_contacts) + '%' }"
                            ></div>
                        </div>
                        <span class="bar-value">{{ selectedCampaign.sent_count }} / {{ selectedCampaign.total_contacts }}</span>
                    </div>
                    <div class="bar-row">
                        <span class="bar-label">Fallidos+Desc.</span>
                        <div class="bar-track">
                            <div
                                class="bar-fill bar-failed"
                                :style="{ width: progressPct(selectedCampaign.failed_count, selectedCampaign.total_contacts) + '%' }"
                            ></div>
                        </div>
                        <span class="bar-value">{{ selectedCampaign.failed_count }}</span>
                    </div>
                </div>

                <!-- Aviso alcance estimado (campaña aún no ejecutada) -->
                <div v-if="selectedCampaign.status === 'draft'" class="estimate-note">
                    <i class="pi pi-info-circle"></i>
                    Alcance estimado: <strong>{{ selectedCampaign.total_contacts }}</strong>
                    contacto{{ selectedCampaign.total_contacts === 1 ? '' : 's' }} activo{{ selectedCampaign.total_contacts === 1 ? '' : 's' }}.
                    Se confirma al ejecutar.
                </div>

                <!-- Stats -->
                <div v-if="detailStats" class="stats-row">
                    <div class="stat-box">
                        <span class="stat-num stat-sent">{{ detailStats.sent }}</span>
                        <span class="stat-lbl">Enviados</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-num stat-failed">{{ detailStats.failed }}</span>
                        <span class="stat-lbl">Fallidos</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-num stat-disc">{{ detailStats.discarded }}</span>
                        <span class="stat-lbl">Descartados</span>
                    </div>
                    <div class="stat-box">
                        <span class="stat-num">{{ detailStats.pending }}</span>
                        <span class="stat-lbl">Pendientes</span>
                    </div>
                </div>

                <!-- Aviso mensajes pendientes: cuándo reanudan -->
                <div
                    v-if="detailStats?.pending > 0 && detailStats?.resumes_at && selectedCampaign?.status === 'running'"
                    class="resumes-notice"
                >
                    <i class="pi pi-clock"></i>
                    {{ detailStats.pending }} mensaje{{ detailStats.pending > 1 ? 's' : '' }} pendiente{{ detailStats.pending > 1 ? 's' : '' }} —
                    reanudarán el <strong>{{ detailStats.resumes_at }}</strong>
                </div>

                <!-- Aviso datos históricos -->
                <div v-if="!detailLoading && detailLogs.length === 0 && selectedCampaign && (selectedCampaign.sent_count + selectedCampaign.failed_count) > 0" class="historical-note">
                    Esta campaña corrió antes de que el sistema comenzara a registrar el detalle por contacto.
                    Solo las campañas nuevas mostrarán la tabla completa.
                </div>

                <!-- Tabla de logs -->
                <DataTable :value="detailLogs" :loading="detailLoading" size="small" stripedRows class="logs-table">
                    <Column header="Teléfono">
                        <template #body="{ data }">
                            <code class="phone-code">{{ data.to_number }}</code>
                        </template>
                    </Column>
                    <Column header="Estado">
                        <template #body="{ data }">
                            <Tag :value="logStatusLabel(data.status)" :severity="logStatusSeverity(data.status)" />
                        </template>
                    </Column>
                    <Column header="Motivo / Error">
                        <template #body="{ data }">
                            <span v-if="data.discard_reason" class="discard-reason">{{ discardLabel(data.discard_reason) }}</span>
                            <span v-else-if="data.error_message" class="error-msg" :title="data.error_message">error Meta</span>
                            <span v-else class="muted-cell">—</span>
                        </template>
                    </Column>
                    <Column header="Procesado (CST)">
                        <template #body="{ data }">
                            <span class="date-cell">{{ data.sent_at }}</span>
                        </template>
                    </Column>
                    <template #empty>
                        <span class="empty-msg">Sin registros de detalle.</span>
                    </template>
                </DataTable>

                <!-- Paginación de logs (prev/next — sin COUNT(*) para no colgar con 200k filas) -->
                <div class="pagination" v-if="detailPrevPage || detailHasMore">
                    <Button icon="pi pi-chevron-left" text severity="secondary"
                        :disabled="!detailPrevPage"
                        @click="loadDetailLogs(selectedCampaign.id, detailPrevPage)" />
                    <span>Página {{ detailCurrentPage }}</span>
                    <Button icon="pi pi-chevron-right" text severity="secondary"
                        :disabled="!detailHasMore"
                        @click="loadDetailLogs(selectedCampaign.id, detailNextPage)" />
                </div>
            </div>

            <template #footer>
                <Button
                    v-if="selectedCampaign?.status === 'draft'"
                    label="Borrar campaña"
                    icon="pi pi-trash"
                    severity="danger"
                    text
                    @click="confirmDelete(selectedCampaign)"
                />
                <Button
                    v-if="selectedCampaign?.status === 'running'"
                    label="Pausar"
                    icon="pi pi-pause"
                    severity="warn"
                    :loading="pausing"
                    @click="doPause(selectedCampaign)"
                />
                <Button
                    v-if="selectedCampaign?.status === 'running' && detailStats?.pending > 0"
                    label="Re-despachar pendientes"
                    icon="pi pi-refresh"
                    severity="secondary"
                    :loading="retrying"
                    @click="doRetryPending(selectedCampaign)"
                />
                <Button label="Cerrar" text @click="showDetail = false" />
            </template>
        </Dialog>

    </div>

    <ConfirmDialog />
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
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
const availableTags    = ref([]);

// ── Estado del dialog de detalle ──────────────────────────────
const showDetail        = ref(false);
const selectedCampaign  = ref(null);
const detailLogs        = ref([]);
const detailHasMore     = ref(false);
const detailCurrentPage = ref(1);
const detailNextPage    = ref(null);
const detailPrevPage    = ref(null);
const detailStats       = ref(null);
const detailLoading     = ref(false);
const pausing           = ref(false);
const retrying          = ref(false);

const form = ref({ name: '', template: null, bodyVars: [], tagId: null });

const tagOptions = computed(() => [
    { label: 'Todos los contactos activos', value: null },
    ...availableTags.value.map(t => ({ label: `Tag: ${t.name}`, value: t.id })),
]);

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
    draft     : 'Borrador',
    running   : 'Ejecutando',
    paused    : 'Pausada',
    completed : 'Finalizada',
    done      : 'Finalizada',
}[s] ?? s);

const statusSeverity = (s) => ({
    draft     : 'secondary',
    running   : 'info',
    paused    : 'warn',
    completed : 'success',
    done      : 'success',
}[s] ?? 'secondary');

const logStatusLabel = (s) => ({
    pending   : 'Pendiente',
    sent      : 'Enviado',
    delivered : 'Entregado',
    read      : 'Leído',
    failed    : 'Fallido',
    discarded : 'Descartado',
}[s] ?? s);

const logStatusSeverity = (s) => ({
    pending   : 'secondary',
    sent      : 'info',
    delivered : 'success',
    read      : 'success',
    failed    : 'danger',
    discarded : 'warn',
}[s] ?? 'secondary');

const discardLabel = (r) => ({
    cooldown   : 'En cooldown',
    snooze     : 'En snooze',
    opted_out  : 'Opt-out',
    dedup_today: 'Ya enviado hoy',
    unreachable: 'Inalcanzable',
}[r] ?? r);

function progressPct(count, total) {
    if (!total) return 0;
    return Math.min(100, Math.round((count / total) * 100));
}

async function loadCampaigns(page = 1) {
    loading.value = true;
    const data       = await api.campaigns({ page });
    campaigns.value  = data.data ?? [];
    meta.value       = data.meta ?? null;
    loading.value    = false;
}

async function openNewModal() {
    form.value    = { name: '', template: null, bodyVars: [], tagId: null };
    formError.value = '';
    showModal.value = true;

    const [, tagsRes] = await Promise.all([
        (async () => {
            if (approvedTemplates.value.length === 0) {
                loadingTemplates.value = true;
                const raw = await api.templates();
                const list = Array.isArray(raw) ? raw : (raw.data ?? []);
                approvedTemplates.value = list
                    .filter(t => t.status === 'approved' && t.is_active)
                    .map(t => ({ ...t, display: `${t.name} (${t.language_code})`, body_text: t.body_text ?? '' }));
                loadingTemplates.value = false;
            }
        })(),
        api.tags(),
    ]);

    if (tagsRes.status === 'ok') availableTags.value = tagsRes.data;
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
        tag_id        : form.value.tagId ?? undefined,
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

let detailPollInterval = null;

function startDetailPolling(campaignId) {
    stopDetailPolling();
    detailPollInterval = setInterval(async () => {
        if (selectedCampaign.value?.status === 'running') {
            await loadDetailLogs(campaignId, detailCurrentPage.value);
        } else {
            stopDetailPolling();
        }
    }, 5000);
}

function stopDetailPolling() {
    if (detailPollInterval) {
        clearInterval(detailPollInterval);
        detailPollInterval = null;
    }
}

async function openDetail(campaign) {
    selectedCampaign.value  = campaign;
    detailLogs.value        = [];
    detailStats.value       = null;
    detailHasMore.value     = false;
    detailCurrentPage.value = 1;
    detailNextPage.value    = null;
    detailPrevPage.value    = null;
    showDetail.value        = true;
    await loadDetailLogs(campaign.id, 1);
    if (campaign.status === 'running') startDetailPolling(campaign.id);
}

async function loadDetailLogs(campaignId, page = 1) {
    detailLoading.value = true;
    const res = await api.campaignLogs(campaignId, { page });
    if (res.status === 'ok') {
        // Actualizar selectedCampaign con datos frescos del servidor (elimina datos rancios del listado)
        if (res.campaign) {
            selectedCampaign.value = { ...selectedCampaign.value, ...res.campaign };
        }
        detailLogs.value        = res.data ?? [];
        detailStats.value       = res.stats ?? null;
        detailHasMore.value     = res.has_more ?? false;
        detailCurrentPage.value = page;
        detailNextPage.value    = res.next_page ?? null;
        detailPrevPage.value    = res.prev_page ?? null;
    }
    detailLoading.value = false;
}

async function doPause(campaign) {
    pausing.value = true;
    const res = await api.pauseCampaign(campaign.id);
    pausing.value = false;
    if (res.status === 'ok') {
        toast.add({ severity: 'warn', summary: 'Campaña pausada', detail: campaign.name, life: 3000 });
        selectedCampaign.value = res.data;
        await loadCampaigns(meta.value?.page ?? 1);
    } else {
        toast.add({ severity: 'error', summary: 'Error', detail: res.message, life: 5000 });
    }
}

async function doRetryPending(campaign) {
    retrying.value = true;
    const res = await api.retryPending(campaign.id);
    retrying.value = false;
    if (res.status === 'ok') {
        toast.add({
            severity : 'success',
            summary  : 'Jobs re-encolados',
            detail   : res.data?.message,
            life     : 4000,
        });
        await loadDetailLogs(campaign.id, 1);
        await loadCampaigns(meta.value?.page ?? 1);
    } else {
        toast.add({ severity: 'error', summary: 'Error', detail: res.message, life: 5000 });
    }
}

function confirmDelete(campaign) {
    confirm.require({
        message    : `¿Borrar la campaña "${campaign.name}"? Esta acción no se puede deshacer.`,
        header     : 'Confirmar borrado',
        icon       : 'pi pi-trash',
        acceptLabel: 'Sí, borrar',
        rejectLabel: 'Cancelar',
        acceptClass: 'p-button-danger',
        accept: async () => {
            const res = await api.deleteCampaign(campaign.id);
            if (res.status === 'ok') {
                showDetail.value = false;
                toast.add({ severity: 'success', summary: 'Campaña borrada', life: 3000 });
                await loadCampaigns(1);
            } else {
                toast.add({ severity: 'error', summary: 'Error', detail: res.message, life: 5000 });
            }
        },
    });
}

onMounted(() => loadCampaigns());
onUnmounted(() => stopDetailPolling());
</script>

<style scoped>
.page-header {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 16px;
}

.campaign-name-link {
    color: var(--p-primary-500);
    cursor: pointer;
    font-weight: 500;
}
.campaign-name-link:hover { text-decoration: underline; }

.progress-cell { font-variant-numeric: tabular-nums; font-size: .85rem; }
.failed-cell   { color: var(--p-red-600); font-size: .85rem; font-variant-numeric: tabular-nums; }
.date-cell     { color: var(--p-text-muted-color); font-size: .82rem; }
.empty-msg     { color: var(--p-text-muted-color); font-size: .85rem; }
.muted-cell    { color: var(--p-text-muted-color); font-size: .82rem; }

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

/* Dialog detalle */
.detail-body { display: flex; flex-direction: column; gap: 16px; }

.progress-bars { display: flex; flex-direction: column; gap: 8px; }
.bar-row {
    display: flex;
    align-items: center;
    gap: 10px;
}
.bar-label {
    font-size: .8rem;
    color: var(--p-text-muted-color);
    width: 110px;
    flex-shrink: 0;
}
.bar-track {
    flex: 1;
    height: 10px;
    background: var(--p-surface-200);
    border-radius: 6px;
    overflow: hidden;
}
.bar-fill {
    height: 100%;
    border-radius: 6px;
    transition: width .4s ease;
}
.bar-sent   { background: var(--p-green-500); }
.bar-failed { background: var(--p-red-400); }
.bar-value {
    font-size: .8rem;
    font-variant-numeric: tabular-nums;
    width: 64px;
    text-align: right;
    color: var(--p-text-muted-color);
}

.stats-row {
    display: flex;
    gap: 12px;
}
.stat-box {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 10px 6px;
    background: var(--p-surface-100);
    border-radius: 8px;
}
.stat-num      { font-size: 1.4rem; font-weight: 700; }
.stat-sent     { color: var(--p-green-600); }
.stat-failed   { color: var(--p-red-600); }
.stat-disc     { color: var(--p-orange-600); }
.stat-lbl      { font-size: .75rem; color: var(--p-text-muted-color); margin-top: 2px; }

.historical-note {
    padding: 8px 14px;
    background: var(--p-surface-100);
    border-left: 3px solid var(--p-surface-400);
    border-radius: 4px;
    font-size: .8rem;
    color: var(--p-text-muted-color);
}

.logs-table { font-size: .83rem; }
.phone-code { font-size: .8rem; }
.discard-reason { color: var(--p-orange-700); font-size: .8rem; }
.error-msg { color: var(--p-red-600); font-size: .8rem; cursor: help; }

.resumes-notice {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: var(--p-blue-50);
    border-left: 3px solid var(--p-primary-400);
    border-radius: 4px;
    font-size: .82rem;
    color: var(--p-primary-700);
    margin-bottom: 12px;
}
.resumes-notice .pi { font-size: .9rem; flex-shrink: 0; }

.estimate-note {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: var(--p-surface-100);
    border-left: 3px solid var(--p-primary-400);
    border-radius: 4px;
    font-size: .82rem;
    color: var(--p-text-muted-color);
}
.estimate-note .pi { font-size: .9rem; flex-shrink: 0; }
</style>
