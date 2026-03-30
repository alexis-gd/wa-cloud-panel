<template>
    <div class="dashboard">
        <!-- Stats mensajes -->
        <div class="stats-row">
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num">{{ stats.sent ?? '—' }}</span>
                        <span class="stat-lbl">Enviados</span>
                    </div>
                </template>
            </Card>
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num delivered">{{ stats.delivered ?? '—' }}</span>
                        <span class="stat-lbl">Entregados</span>
                    </div>
                </template>
            </Card>
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num read">{{ stats.read ?? '—' }}</span>
                        <span class="stat-lbl">Leídos</span>
                    </div>
                </template>
            </Card>
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num failed">{{ stats.failed ?? '—' }}</span>
                        <span class="stat-lbl">Fallidos</span>
                    </div>
                </template>
            </Card>
        </div>

        <!-- Stats contactos -->
        <div class="stats-row contacts-row">
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num">{{ contacts.total ?? '—' }}</span>
                        <span class="stat-lbl">Total contactos</span>
                    </div>
                </template>
            </Card>
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num active">{{ contacts.active ?? '—' }}</span>
                        <span class="stat-lbl">Activos</span>
                    </div>
                </template>
            </Card>
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num opted-out">{{ contacts.opted_out ?? '—' }}</span>
                        <span class="stat-lbl">Opt-out</span>
                    </div>
                </template>
            </Card>
            <Card class="stat-card">
                <template #content>
                    <div class="stat">
                        <span class="stat-num failed">{{ contacts.invalid ?? '—' }}</span>
                        <span class="stat-lbl">Inválidos</span>
                    </div>
                </template>
            </Card>
        </div>

        <div class="dashboard-grid">
            <!-- Enviar mensaje de prueba -->
            <Card>
                <template #title>Enviar mensaje de prueba</template>
                <template #content>
                    <div class="form-group">
                        <label>Plantilla</label>
                        <Select
                            v-model="form.template_name"
                            :options="templateOptions"
                            option-label="label"
                            option-value="value"
                            placeholder="Selecciona una plantilla aprobada"
                            fluid
                            :loading="loadingTemplates"
                        />
                        <small v-if="templateOptions.length === 0 && !loadingTemplates" class="warn-msg">
                            No hay plantillas aprobadas disponibles.
                        </small>
                    </div>
                    <div class="form-group">
                        <label>Número destino (con código de país, sin +)</label>
                        <InputText v-model="form.to" placeholder="529231311146" fluid />
                    </div>
                    <div class="form-group">
                        <label>Variables (separadas por coma)</label>
                        <InputText v-model="form.body_vars_raw" placeholder="Nombre, Monto" fluid />
                    </div>
                    <Button
                        label="Enviar"
                        icon="pi pi-send"
                        :loading="sending"
                        :disabled="!form.template_name || !form.to"
                        @click="sendTest"
                        class="mt-2"
                    />
                    <Message v-if="sendResult" :severity="sendResult.status === 'sent' ? 'success' : 'error'" class="mt-3">
                        {{ sendResult.status === 'sent' ? 'Mensaje enviado correctamente' : sendResult.message }}
                    </Message>
                </template>
            </Card>

            <!-- Últimos mensajes -->
            <Card>
                <template #title>
                    <div class="card-title-row">
                        <span>Últimos mensajes</span>
                        <Button icon="pi pi-refresh" severity="secondary" text @click="loadStats" :loading="loadingLogs" />
                    </div>
                </template>
                <template #content>
                    <DataTable :value="logs" size="small" :rows="10" stripedRows>
                        <Column field="id" header="ID" style="width: 60px" />
                        <Column field="to_number" header="Destino" />
                        <Column field="template_name" header="Plantilla" />
                        <Column header="Estado">
                            <template #body="{ data }">
                                <Tag :value="data.status" :severity="statusSeverity(data.status)" />
                            </template>
                        </Column>
                        <Column header="Fecha">
                            <template #body="{ data }">
                                {{ data.created_at?.substring(0, 16).replace('T', ' ') }}
                            </template>
                        </Column>
                        <template #empty>
                            <span class="empty-msg">Sin mensajes aún</span>
                        </template>
                    </DataTable>
                </template>
            </Card>
        </div>
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import Card      from 'primevue/card';
import Button    from 'primevue/button';
import InputText from 'primevue/inputtext';
import Select    from 'primevue/select';
import DataTable from 'primevue/datatable';
import Column    from 'primevue/column';
import Tag       from 'primevue/tag';
import Message   from 'primevue/message';
import { api }   from '../api.js';

const logs            = ref([]);
const stats           = ref({});
const contacts        = ref({});
const sending         = ref(false);
const loadingLogs     = ref(false);
const loadingTemplates = ref(false);
const sendResult      = ref(null);
const templateOptions = ref([]);

const form = ref({
    template_name: null,
    to:            '',
    body_vars_raw: '',
});

const statusSeverity = (status) => ({
    sent      : 'info',
    delivered : 'success',
    read      : 'secondary',
    failed    : 'danger',
    pending   : 'warn',
}[status] ?? 'secondary');

async function loadStats() {
    loadingLogs.value = true;
    const res = await api.dashboardStats();
    if (res.status === 'ok') {
        stats.value    = res.data.stats    ?? {};
        contacts.value = res.data.contacts ?? {};
        logs.value     = res.data.recent_messages ?? [];
    }
    loadingLogs.value = false;
}

async function loadTemplates() {
    loadingTemplates.value = true;
    const res = await api.templates();
    templateOptions.value = (res.data ?? []).map(t => ({
        label: `${t.name} (${t.language_code})`,
        value: t.name,
        language_code: t.language_code,
    }));
    loadingTemplates.value = false;
}

async function sendTest() {
    sending.value    = true;
    sendResult.value = null;

    const selected = templateOptions.value.find(t => t.value === form.value.template_name);
    const bodyVars = form.value.body_vars_raw
        ? form.value.body_vars_raw.split(',').map(v => v.trim()).filter(Boolean)
        : [];

    sendResult.value = await api.sendTest({
        template_name: form.value.template_name,
        language_code: selected?.language_code ?? 'en_US',
        to:            form.value.to,
        body_vars:     bodyVars,
    });

    sending.value = false;
    await loadStats();
}

onMounted(() => {
    loadStats();
    loadTemplates();
});
</script>

<style scoped>
.stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 16px;
}

.stat { text-align: center; }
.stat-num           { display: block; font-size: 2rem; font-weight: 700; color: var(--p-text-color); }
.stat-num.delivered { color: var(--p-green-500); }
.stat-num.active    { color: var(--p-green-500); }
.stat-num.read      { color: var(--p-primary-color); }
.stat-num.failed    { color: var(--p-red-500); }
.stat-num.opted-out { color: var(--p-orange-500); }
.stat-lbl { display: block; font-size: .75rem; color: var(--p-text-muted-color); margin-top: 4px; }

.dashboard-grid {
    display: grid;
    grid-template-columns: 380px 1fr;
    gap: 20px;
    align-items: start;
}

@media (max-width: 900px) {
    .stats-row        { grid-template-columns: repeat(2, 1fr); }
    .dashboard-grid   { grid-template-columns: 1fr; }
}

@media (max-width: 480px) {
    .stats-row  { gap: 10px; }
    .stat-num   { font-size: 1.5rem; }
}

.form-group       { margin-bottom: 12px; }
.form-group label { display: block; font-size: .82rem; color: var(--p-text-muted-color); margin-bottom: 4px; }

.card-title-row { display: flex; justify-content: space-between; align-items: center; width: 100%; }
.empty-msg      { color: var(--p-text-muted-color); font-size: .85rem; }
.warn-msg       { color: var(--p-orange-500); font-size: .78rem; }
.mt-2 { margin-top: 8px; }
.mt-3 { margin-top: 12px; }
</style>
