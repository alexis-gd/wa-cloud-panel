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

        <!-- Salud del número -->
        <Card class="health-card mb-4">
            <template #content>
                <div class="health-row">
                    <div class="health-item">
                        <span class="health-label">Calidad del número</span>
                        <span class="health-badge" :class="qualityClass">
                            <span class="health-dot"></span>
                            {{ healthData.quality_rating ?? '…' }}
                        </span>
                    </div>
                    <div class="health-item">
                        <span class="health-label">Modo</span>
                        <span class="health-value">{{ healthData.account_mode ?? '…' }}</span>
                    </div>
                    <div class="health-item">
                        <span class="health-label">Enviados hoy / límite</span>
                        <span class="health-value">
                            {{ healthData.sent_today ?? '…' }} / {{ healthData.daily_limit ?? '…' }}
                        </span>
                    </div>
                    <div class="health-item">
                        <span class="health-label">Número</span>
                        <span class="health-value">{{ healthData.display_phone ?? '…' }}</span>
                    </div>
                    <div v-if="healthData.is_paused" class="health-item">
                        <span class="health-label">Circuit breaker</span>
                        <span class="health-badge quality-red">
                            <span class="health-dot"></span>
                            PAUSADO hasta {{ healthData.paused_until?.substring(0, 16).replace('T', ' ') }}
                        </span>
                    </div>
                    <Button icon="pi pi-refresh" text severity="secondary" size="small" :loading="loadingHealth" @click="loadHealth" class="health-refresh" />
                </div>
                <div v-if="healthError" class="health-error">{{ healthError }}</div>
            </template>
        </Card>

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

        <!-- Gráfica envíos por día -->
        <Card class="chart-card">
            <template #title>
                <div class="card-title-row">
                    <span>Envíos últimos 14 días</span>
                    <Button icon="pi pi-refresh" severity="secondary" text size="small" :loading="loadingChart" @click="loadDailyStats" />
                </div>
            </template>
            <template #content>
                <div v-if="loadingChart || !chartData.labels?.length" class="chart-loading">Cargando...</div>
                <Chart v-else type="bar" :data="chartData" :options="chartOptions" class="send-chart" />
            </template>
        </Card>

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
                        <label>Contacto destino</label>
                        <Select
                            v-model="form.to"
                            :options="contactOptions"
                            option-label="label"
                            option-value="value"
                            placeholder="Selecciona un contacto activo"
                            fluid
                            :loading="loadingContacts"
                            filter
                        />
                    </div>
                    <template v-if="selectedTemplate && templateVars.length">
                        <div v-for="(varName, idx) in templateVars" :key="idx" class="form-group">
                            <label>{{ varName }}</label>
                            <InputText v-model="form.vars[idx]" :placeholder="varName" fluid />
                        </div>
                    </template>
                    <div v-else-if="selectedTemplate && !templateVars.length" class="form-group">
                        <small class="muted-msg">Esta plantilla no tiene variables.</small>
                    </div>
                    <Button
                        label="Enviar"
                        icon="pi pi-send"
                        :loading="sending"
                        :disabled="!form.template_name || !form.to || sending"
                        @click="sendTest"
                        class="mt-2"
                    />
                    <Message v-if="sendResult" :severity="sendResult.status === 'sent' ? 'success' : 'error'" class="mt-3">
                        {{ sendResult.status === 'sent'
                            ? 'Mensaje enviado correctamente'
                            : (sendResult.wa_response?.error?.message || sendResult.message || 'Error al enviar') }}
                    </Message>
                </template>
            </Card>

            <!-- Últimos mensajes -->
            <Card>
                <template #title>
                    <div class="card-title-row">
                        <span>Últimos mensajes</span>
                        <div class="title-actions">
                            <Button icon="pi pi-download" severity="secondary" text @click="downloadMessages" title="Exportar Excel" />
                            <Button icon="pi pi-refresh" severity="secondary" text @click="loadMessages(1)" :loading="loadingLogs" />
                        </div>
                    </div>
                </template>
                <template #content>
                    <div class="logs-filter-row">
                        <Select
                            v-model="logsStatusFilter"
                            :options="logStatusOptions"
                            option-label="label"
                            option-value="value"
                            placeholder="Todos los estados"
                            size="small"
                            @change="loadMessages(1)"
                        />
                    </div>
                    <DataTable :value="logs" size="small" stripedRows :loading="loadingLogs" class="mt-2">
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
                    <div class="logs-pagination" v-if="logsMeta">
                        <Button icon="pi pi-chevron-left" text severity="secondary" size="small"
                            :disabled="logsMeta.page <= 1" @click="loadMessages(logsMeta.page - 1)" />
                        <span class="logs-page-info">{{ logsMeta.page }} / {{ logsMeta.pages }}</span>
                        <Button icon="pi pi-chevron-right" text severity="secondary" size="small"
                            :disabled="logsMeta.page >= logsMeta.pages" @click="loadMessages(logsMeta.page + 1)" />
                        <span class="logs-total">{{ logsMeta.total }} mensajes</span>
                    </div>
                </template>
            </Card>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import Card      from 'primevue/card';
import Button    from 'primevue/button';
import InputText from 'primevue/inputtext';
import Select    from 'primevue/select';
import DataTable from 'primevue/datatable';
import Column    from 'primevue/column';
import Tag       from 'primevue/tag';
import Message   from 'primevue/message';
import Chart     from 'primevue/chart';
import { api }   from '../api.js';

const logs             = ref([]);
const logsMeta         = ref(null);
const logsStatusFilter = ref(null);
const stats            = ref({});
const contacts         = ref({});
const sending          = ref(false);
const loadingLogs      = ref(false);
const loadingTemplates = ref(false);
const loadingContacts  = ref(false);
const loadingHealth    = ref(false);
const sendResult       = ref(null);

const logStatusOptions = [
    { label: 'Todos', value: null },
    { label: 'Enviados',    value: 'sent' },
    { label: 'Entregados',  value: 'delivered' },
    { label: 'Leídos',      value: 'read' },
    { label: 'Fallidos',    value: 'failed' },
    { label: 'Pendientes',  value: 'pending' },
];
const templateOptions  = ref([]); // [{ label, value, language_code, body_text, var_labels }]
const contactOptions   = ref([]); // [{ label, value }] solo contactos activos
const healthData       = ref({});
const healthError      = ref(null);
const loadingChart     = ref(false);
const chartData        = ref({});
const chartOptions     = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { position: 'bottom' },
        tooltip: { mode: 'index', intersect: false },
    },
    scales: {
        x: { stacked: false },
        y: { beginAtZero: true, ticks: { precision: 0 } },
    },
};

const form = ref({
    template_name: null,
    to:            '',
    vars:          [],
});

// Plantilla actualmente seleccionada (objeto completo)
const selectedTemplate = computed(() =>
    templateOptions.value.find(t => t.value === form.value.template_name) ?? null
);

// Etiquetas de variables extraídas del body_text (e.g. ["Nombre", "Monto"])
const templateVars = computed(() => selectedTemplate.value?.var_labels ?? []);

// Cuando cambia la plantilla: resetear vars al número correcto
watch(selectedTemplate, (tpl) => {
    form.value.vars = tpl ? Array(tpl.var_labels.length).fill('') : [];
});

const statusSeverity = (status) => ({
    sent      : 'info',
    delivered : 'success',
    read      : 'secondary',
    failed    : 'danger',
    pending   : 'warn',
}[status] ?? 'secondary');

// Extrae etiquetas de variables de un body_text.
// Busca patrones {{Nombre}}, {{Monto}} (con texto) o {{1}}, {{2}} (numérico).
function extractVarLabels(bodyText) {
    if (!bodyText) return [];
    const matches = [...bodyText.matchAll(/\{\{([^}]+)\}\}/g)];
    return matches.map(m => {
        const inner = m[1].trim();
        return /^\d+$/.test(inner) ? `Variable ${inner}` : inner;
    });
}

async function loadHealth() {
    loadingHealth.value = true;
    healthError.value   = null;
    const res = await api.phoneHealth();
    if (res.status === 'ok') {
        healthData.value = res.data;
    } else {
        healthError.value = res.message ?? 'No se pudo obtener el estado del número';
    }
    loadingHealth.value = false;
}

const qualityClass = computed(() => ({
    'quality-green'   : healthData.value.quality_rating === 'GREEN',
    'quality-yellow'  : healthData.value.quality_rating === 'YELLOW',
    'quality-red'     : healthData.value.quality_rating === 'RED',
    'quality-unknown' : !['GREEN','YELLOW','RED'].includes(healthData.value.quality_rating),
}));

async function loadStats() {
    const res = await api.dashboardStats();
    if (res.status === 'ok') {
        stats.value    = res.data.stats    ?? {};
        contacts.value = res.data.contacts ?? {};
    }
}

async function loadMessages(page = 1) {
    loadingLogs.value = true;
    const params = { page, per_page: 20 };
    if (logsStatusFilter.value) params.status = logsStatusFilter.value;
    const res = await api.dashboardMessages(params);
    if (res.status === 'ok') {
        logs.value     = res.data   ?? [];
        logsMeta.value = res.meta   ?? null;
    }
    loadingLogs.value = false;
}

async function loadDailyStats() {
    loadingChart.value = true;
    const res = await api.dashboardDailyStats();
    if (res.status === 'ok') {
        const series = res.data;
        const labels = series.map(d => d.day.substring(5)); // MM-DD
        chartData.value = {
            labels,
            datasets: [
                {
                    label          : 'Enviados',
                    data           : series.map(d => d.sent),
                    backgroundColor: 'rgba(99,102,241,0.7)',
                    borderRadius   : 4,
                },
                {
                    label          : 'Entregados',
                    data           : series.map(d => d.delivered),
                    backgroundColor: 'rgba(34,197,94,0.7)',
                    borderRadius   : 4,
                },
                {
                    label          : 'Leídos',
                    data           : series.map(d => d.read),
                    backgroundColor: 'rgba(14,165,233,0.7)',
                    borderRadius   : 4,
                },
                {
                    label          : 'Fallidos',
                    data           : series.map(d => d.failed),
                    backgroundColor: 'rgba(239,68,68,0.7)',
                    borderRadius   : 4,
                },
            ],
        };
    }
    loadingChart.value = false;
}

async function loadTemplates() {
    loadingTemplates.value = true;
    const res = await api.templates();
    templateOptions.value = (res.data ?? [])
        .filter(t => t.status === 'approved' && t.is_active)
        .map(t => ({
            label         : t.name,
            value         : t.name,
            language_code : t.language_code,
            body_text     : t.body_text,
            var_labels    : extractVarLabels(t.body_text),
        }));
    loadingTemplates.value = false;
}

async function loadContactOptions() {
    loadingContacts.value = true;
    const data = await api.contacts({ status: 'active', per_page: 200 });
    contactOptions.value = (data.data ?? []).map(c => ({
        label : c.name ? `${c.name} — ${c.phone}` : c.phone,
        value : c.phone,
    }));
    loadingContacts.value = false;
}

async function downloadMessages() {
    const token = localStorage.getItem('wa_token');
    const res   = await fetch('/api/export/messages', {
        headers: token ? { 'Authorization': `Bearer ${token}` } : {},
    });
    if (!res.ok) return;
    const blob     = await res.blob();
    const url      = URL.createObjectURL(blob);
    const filename = res.headers.get('content-disposition')?.match(/filename="(.+)"/)?.[1]
                     ?? 'mensajes_export.xlsx';
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.click();
    URL.revokeObjectURL(url);
}

async function sendTest() {
    sending.value    = true;
    sendResult.value = null;

    sendResult.value = await api.sendTest({
        template_name : form.value.template_name,
        language_code : selectedTemplate.value?.language_code ?? 'es_MX',
        to            : form.value.to,
        body_vars     : form.value.vars.filter(v => v !== ''),
    });

    sending.value = false;
    await Promise.all([loadStats(), loadMessages(1)]);
}

onMounted(() => {
    loadStats();
    loadMessages(1);
    loadDailyStats();
    loadTemplates();
    loadHealth();
    loadContactOptions();
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

/* Health widget */
.health-card  { margin-bottom: 16px; }
.health-row   { display: flex; align-items: center; gap: 32px; flex-wrap: wrap; }
.health-item  { display: flex; flex-direction: column; gap: 2px; }
.health-label { font-size: .75rem; color: var(--p-text-muted-color); }
.health-value { font-size: .95rem; font-weight: 600; }
.health-refresh { margin-left: auto; }
.health-error { margin-top: 8px; font-size: .82rem; color: var(--p-red-500); }

.health-badge {
    display     : inline-flex;
    align-items : center;
    gap         : 6px;
    font-size   : .95rem;
    font-weight : 700;
}
.health-dot {
    width         : 10px;
    height        : 10px;
    border-radius : 50%;
    flex-shrink   : 0;
}
.quality-green  .health-dot { background: var(--p-green-500); }
.quality-green              { color: var(--p-green-600); }
.quality-yellow .health-dot { background: var(--p-yellow-500); }
.quality-yellow             { color: var(--p-yellow-700); }
.quality-red    .health-dot { background: var(--p-red-500); }
.quality-red                { color: var(--p-red-600); }
.quality-unknown .health-dot { background: var(--p-text-muted-color); }
.quality-unknown             { color: var(--p-text-muted-color); }

.mb-4 { margin-bottom: 20px; }

/* Chart */
.chart-card   { margin-bottom: 20px; }
.chart-loading { text-align: center; padding: 48px; color: var(--p-text-muted-color); font-size: .85rem; }
.send-chart   { height: 240px; }

.form-group       { margin-bottom: 12px; }
.form-group label { display: block; font-size: .82rem; color: var(--p-text-muted-color); margin-bottom: 4px; }

.card-title-row { display: flex; justify-content: space-between; align-items: center; width: 100%; }
.title-actions  { display: flex; gap: 2px; }
.empty-msg      { color: var(--p-text-muted-color); font-size: .85rem; }
.warn-msg       { color: var(--p-orange-500); font-size: .78rem; }
.muted-msg      { color: var(--p-text-muted-color); font-size: .78rem; }
.mt-2 { margin-top: 8px; }
.mt-3 { margin-top: 12px; }

.logs-filter-row   { display: flex; gap: 8px; margin-bottom: 8px; }
.logs-pagination   { display: flex; align-items: center; gap: 4px; margin-top: 8px; font-size: .82rem; }
.logs-page-info    { padding: 0 4px; }
.logs-total        { color: var(--p-text-muted-color); margin-left: 8px; }
</style>
