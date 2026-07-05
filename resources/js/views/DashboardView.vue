<template>
    <div class="dashboard">

        <!-- 1. Calidad del número -->
        <Card class="health-card mb">
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

        <!-- 2. Envíos del mes -->
        <Card class="monthly-card mb">
            <template #content>
                <div class="monthly-header">
                    <div class="monthly-title">
                        <div class="monthly-label-row">
                            <span class="monthly-label">Envíos del mes</span>
                            <i
                                class="pi pi-info-circle monthly-info"
                                v-tooltip.top="'Mensajes enviados en ' + monthly.month_label + ' vs. la capacidad real del sistema (días hábiles × límite diario del número). Los fines de semana no cuentan.'"
                            ></i>
                        </div>
                        <span class="monthly-month">{{ monthly.month_label }}</span>
                    </div>
                    <div class="monthly-numbers">
                        <span class="monthly-sent">{{ (monthly.sent ?? 0).toLocaleString('es-MX') }}</span>
                        <span class="monthly-sep"> / </span>
                        <span class="monthly-capacity">{{ (monthly.capacity ?? 0).toLocaleString('es-MX') }}</span>
                        <span class="monthly-pct" :class="pctClass">{{ monthly.pct ?? 0 }}%</span>
                    </div>
                </div>
                <div class="monthly-bar-track">
                    <div
                        class="monthly-bar-fill"
                        :class="pctClass"
                        :style="{ width: (monthly.pct ?? 0) + '%' }"
                    ></div>
                </div>
                <div class="monthly-footer">
                    <span class="monthly-meta">
                        {{ monthly.working_days_total }} días hábiles en el mes
                        · {{ monthly.daily_limit ?? 0 }} mensajes/día de capacidad
                    </span>
                    <span class="monthly-remaining" v-if="(monthly.capacity - monthly.sent) > 0">
                        Faltan {{ ((monthly.capacity ?? 0) - (monthly.sent ?? 0)).toLocaleString('es-MX') }}
                    </span>
                    <span class="monthly-done" v-else-if="monthly.capacity > 0">¡Capacidad usada al 100%!</span>
                </div>
            </template>
        </Card>

        <!-- 3. Stats de mensajes -->
        <div class="section-header mb-xs">
            <i class="pi pi-send section-icon"></i>
            <span class="section-title">Estado de mensajes</span>
            <span class="section-subtitle">— etapas por las que pasa cada mensaje enviado</span>
        </div>
        <div class="stats-row mb">
            <Card class="stat-card" v-for="s in messageStats" :key="s.key">
                <template #content>
                    <div class="stat">
                        <div class="stat-label-row">
                            <span class="stat-lbl">{{ s.label }}</span>
                            <i class="pi pi-info-circle stat-info" v-tooltip.top="s.tooltip"></i>
                        </div>
                        <span class="stat-num" :class="s.class">{{ stats[s.key] ?? '—' }}</span>
                    </div>
                </template>
            </Card>
        </div>

        <!-- 4. Stats de contactos -->
        <div class="section-header mb-xs">
            <i class="pi pi-users section-icon"></i>
            <span class="section-title">Base de contactos</span>
            <span class="section-subtitle">— estado actual de todos los contactos registrados</span>
        </div>
        <div class="stats-row mb">
            <Card class="stat-card" v-for="c in contactStats" :key="c.key">
                <template #content>
                    <div class="stat">
                        <div class="stat-label-row">
                            <span class="stat-lbl">{{ c.label }}</span>
                            <i class="pi pi-info-circle stat-info" v-tooltip.top="c.tooltip"></i>
                        </div>
                        <span class="stat-num" :class="c.class">{{ contacts[c.key] ?? '—' }}</span>
                    </div>
                </template>
            </Card>
        </div>

        <!-- 5. Gráfica envíos del mes -->
        <Card v-if="isEnabled('feature_daily_chart')" class="chart-card mb">
            <template #title>
                <div class="card-title-row">
                    <span>Envíos día a día — {{ monthly.month_label }}</span>
                    <Button icon="pi pi-refresh" severity="secondary" text size="small" :loading="loadingChart" @click="loadDailyStats" />
                </div>
            </template>
            <template #content>
                <div v-if="loadingChart || !chartData.labels?.length" class="chart-loading">Cargando...</div>
                <Chart v-else type="bar" :data="chartData" :options="chartOptions" class="send-chart" />
            </template>
        </Card>

        <!-- 6. Histórico mensual -->
        <Card class="history-card mb">
            <template #title>
                <div class="card-title-row">
                    <span>Histórico por mes</span>
                    <i class="pi pi-info-circle stat-info" v-tooltip.top="'Mensajes enviados vs. capacidad máxima de cada mes (días hábiles × límite diario). La capacidad histórica usa el límite actual como referencia.'"></i>
                </div>
            </template>
            <template #content>
                <div v-if="loadingHistory" class="chart-loading">Cargando...</div>
                <table v-else class="history-table">
                    <thead>
                        <tr>
                            <th>Mes</th>
                            <th class="num-col">Enviados</th>
                            <th class="num-col">Capacidad</th>
                            <th class="num-col">Uso</th>
                            <th class="bar-col">Avance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in [...monthlyHistory].reverse()" :key="row.month" :class="{ 'row-current': row.month === currentMonthKey }">
                            <td class="month-cell">
                                {{ row.month_label }}
                                <span v-if="row.month === currentMonthKey" class="current-badge">actual</span>
                            </td>
                            <td class="num-col">{{ row.sent.toLocaleString('es-MX') }}</td>
                            <td class="num-col muted">{{ row.capacity.toLocaleString('es-MX') }}</td>
                            <td class="num-col">
                                <span :class="pctColorClass(row.pct)">{{ row.pct }}%</span>
                            </td>
                            <td class="bar-col">
                                <div class="mini-bar-track">
                                    <div class="mini-bar-fill" :class="pctColorClass(row.pct)" :style="{ width: row.pct + '%' }"></div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
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
                    <Column header="Canal" style="width: 70px">
                        <template #body="{ data }">
                            <i
                                :class="['pi', data.channel === 'sms' ? 'pi-envelope ch-sms' : 'pi-whatsapp ch-wa']"
                                v-tooltip.top="data.channel === 'sms' ? 'SMS' : 'WhatsApp'"
                            ></i>
                        </template>
                    </Column>
                    <Column field="to_number" header="Destino" />
                    <Column header="Plantilla">
                        <template #body="{ data }">
                            <code v-if="data.template_name">{{ data.template_name }}</code>
                            <span v-else class="muted-cell">-</span>
                        </template>
                    </Column>
                    <Column header="Estado">
                        <template #body="{ data }">
                            <Tag :value="data.status" :severity="statusSeverity(data.status)" />
                        </template>
                    </Column>
                    <Column header="Fecha">
                        <template #body="{ data }">
                            {{ data.created_at }}
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
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import Card      from 'primevue/card';
import Button    from 'primevue/button';
import Select    from 'primevue/select';
import DataTable from 'primevue/datatable';
import Column    from 'primevue/column';
import Tag       from 'primevue/tag';
import Chart     from 'primevue/chart';
import { api }          from '../api.js';
import { useFeatures }  from '../features.js';

// ── Estado ────────────────────────────────────────────────────────────────────
const logs             = ref([]);
const { isEnabled } = useFeatures();
const logsMeta         = ref(null);
const logsStatusFilter = ref(null);
const stats            = ref({});
const contacts         = ref({});
const monthly          = ref({});
const monthlyHistory   = ref([]);
const loadingLogs      = ref(false);
const loadingHealth    = ref(false);
const loadingChart     = ref(false);
const loadingHistory   = ref(false);
const healthData       = ref({});
const healthError      = ref(null);
const chartData        = ref({});

const currentMonthKey = computed(() => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
});

// ── Tooltips de métricas ──────────────────────────────────────────────────────
const messageStats = [
    {
        key: 'sent', label: 'En tránsito', class: '',
        tooltip: 'Mensajes que salieron hacia Meta pero aún no se confirmó que llegaron al celular. Cuando Meta confirma la entrega, este número baja y sube "Entregados".',
    },
    {
        key: 'delivered', label: 'Entregados', class: 'delivered',
        tooltip: 'Meta confirmó que el mensaje llegó al celular del contacto. Siempre es ≤ Enviados.',
    },
    {
        key: 'read', label: 'Leídos', class: 'read',
        tooltip: 'El contacto abrió el mensaje en WhatsApp. Puede aparecer mayor que Enviados porque incluye mensajes de días anteriores que el contacto leyó recientemente.',
    },
    {
        key: 'failed', label: 'Fallidos', class: 'failed',
        tooltip: 'No se pudo enviar el mensaje: número inexistente en WhatsApp, cuenta pausada u otro error de Meta. Ver detalle en la tabla de campañas.',
    },
];

const contactStats = [
    {
        key: 'total', label: 'Total contactos', class: '',
        tooltip: 'Todos los contactos en la base de datos, sin importar su estado.',
    },
    {
        key: 'active', label: 'Activos', class: 'active',
        tooltip: 'Contactos que pueden recibir mensajes de campaña. No están dados de baja ni marcados como inválidos.',
    },
    {
        key: 'opted_out', label: 'Bajas', class: 'opted-out',
        tooltip: 'Contactos que pidieron no recibir mensajes (respondieron STOP, NO, BAJA o CANCELAR). 0 bajas es una buena señal. Son irreversibles y nunca se eliminan de la BD.',
    },
    {
        key: 'invalid', label: 'Inválidos', class: 'failed',
        tooltip: 'Números que no tienen WhatsApp o que Meta rechazó. Se marcan automáticamente al primer fallo con código de error 131026. No se vuelven a intentar.',
    },
];

// ── Computed ──────────────────────────────────────────────────────────────────
const logStatusOptions = [
    { label: 'Todos', value: null },
    { label: 'Enviados',   value: 'sent' },
    { label: 'Entregados', value: 'delivered' },
    { label: 'Leídos',     value: 'read' },
    { label: 'Fallidos',   value: 'failed' },
    { label: 'Pendientes', value: 'pending' },
];

const chartOptions = {
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

const pctClass = computed(() => pctColorClass(monthly.value.pct ?? 0));

function pctColorClass(pct) {
    if (pct >= 100) return 'pct-done';
    if (pct >= 60)  return 'pct-ok';
    if (pct >= 30)  return 'pct-warn';
    return 'pct-low';
}

const qualityClass = computed(() => ({
    'quality-green'  : healthData.value.quality_rating === 'GREEN',
    'quality-yellow' : healthData.value.quality_rating === 'YELLOW',
    'quality-red'    : healthData.value.quality_rating === 'RED',
    'quality-unknown': !['GREEN','YELLOW','RED'].includes(healthData.value.quality_rating),
}));

const statusSeverity = (status) => ({
    sent      : 'info',
    delivered : 'success',
    read      : 'secondary',
    failed    : 'danger',
    pending   : 'warn',
}[status] ?? 'secondary');

// ── Loaders ───────────────────────────────────────────────────────────────────
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

async function loadStats() {
    const res = await api.dashboardStats();
    if (res.status === 'ok') {
        stats.value    = res.data.stats    ?? {};
        contacts.value = res.data.contacts ?? {};
        monthly.value  = res.data.monthly  ?? {};
    }
}

async function loadMessages(page = 1) {
    loadingLogs.value = true;
    const params = { page, per_page: 10 };
    if (logsStatusFilter.value) params.status = logsStatusFilter.value;
    const res = await api.dashboardMessages(params);
    if (res.status === 'ok') {
        logs.value     = res.data ?? [];
        logsMeta.value = res.meta ?? null;
    }
    loadingLogs.value = false;
}

async function loadDailyStats() {
    loadingChart.value = true;
    const res = await api.dashboardDailyStats();
    if (res.status === 'ok') {
        const series = res.data;
        // Mostrar solo el día del mes (DD) para no saturar el eje X
        const labels = series.map(d => d.day.substring(8)); // día DD
        chartData.value = {
            labels,
            datasets: [
                { label: 'Enviados',   data: series.map(d => d.sent),      backgroundColor: 'rgba(99,102,241,0.7)',  borderRadius: 4 },
                { label: 'Entregados', data: series.map(d => d.delivered), backgroundColor: 'rgba(34,197,94,0.7)',   borderRadius: 4 },
                { label: 'Leídos',     data: series.map(d => d.read),      backgroundColor: 'rgba(14,165,233,0.7)',  borderRadius: 4 },
                { label: 'Fallidos',   data: series.map(d => d.failed),    backgroundColor: 'rgba(239,68,68,0.7)',   borderRadius: 4 },
            ],
        };
    }
    loadingChart.value = false;
}

async function loadMonthlyHistory() {
    loadingHistory.value = true;
    const res = await api.dashboardMonthlyHistory();
    if (res.status === 'ok') {
        monthlyHistory.value = res.data ?? [];
    }
    loadingHistory.value = false;
}

async function downloadMessages() {
    const token = localStorage.getItem('wa_token');
    const res   = await fetch('/api/export/messages', {
        headers: token ? { 'Authorization': `Bearer ${token}` } : {},
    });
    if (!res.ok) return;
    const blob     = await res.blob();
    const url      = URL.createObjectURL(blob);
    const filename = res.headers.get('content-disposition')?.match(/filename="(.+)"/)?.[1] ?? 'mensajes_export.xlsx';
    const a = document.createElement('a');
    a.href = url; a.download = filename; a.click();
    URL.revokeObjectURL(url);
}

onMounted(() => {
    loadHealth();
    loadStats();
    loadMessages(1);
    loadDailyStats();
    loadMonthlyHistory();
});
</script>

<style scoped>
.mb    { margin-bottom: 16px; }
.mb-xs { margin-bottom: 6px; }

/* ── Section headers ────────────────────────────────────────── */
.section-header {
    display: flex;
    align-items: center;
    gap: 7px;
}
.section-icon     { font-size: .85rem; color: var(--p-primary-500); }
.section-title    { font-size: .82rem; font-weight: 700; color: var(--p-text-color); }
.section-subtitle { font-size: .78rem; color: var(--p-text-muted-color); }

/* ── Health ─────────────────────────────────────────────────── */
.health-row   { display: flex; align-items: center; gap: 32px; flex-wrap: wrap; }
.health-item  { display: flex; flex-direction: column; gap: 2px; }
.health-label { font-size: .75rem; color: var(--p-text-muted-color); }
.health-value { font-size: .95rem; font-weight: 600; }
.health-refresh { margin-left: auto; }
.health-error { margin-top: 8px; font-size: .82rem; color: var(--p-red-500); }

.health-badge {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: .95rem; font-weight: 700;
}
.health-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.quality-green  .health-dot { background: var(--p-green-500); }
.quality-green              { color: var(--p-green-600); }
.quality-yellow .health-dot { background: var(--p-yellow-500); }
.quality-yellow             { color: var(--p-yellow-700); }
.quality-red    .health-dot { background: var(--p-red-500); }
.quality-red                { color: var(--p-red-600); }
.quality-unknown .health-dot { background: var(--p-text-muted-color); }
.quality-unknown             { color: var(--p-text-muted-color); }

/* ── Monthly ────────────────────────────────────────────────── */
.monthly-header {
    display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 10px;
}
.monthly-title  { display: flex; flex-direction: column; gap: 2px; }
.monthly-label-row { display: flex; align-items: center; gap: 5px; }
.monthly-label  { font-size: .75rem; color: var(--p-text-muted-color); text-transform: uppercase; letter-spacing: .04em; }
.monthly-info   { font-size: .75rem; color: var(--p-text-muted-color); cursor: help; }
.monthly-month  { font-size: .95rem; font-weight: 600; text-transform: capitalize; }
.monthly-numbers { display: flex; align-items: baseline; gap: 4px; }
.monthly-sent     { font-size: 1.4rem; font-weight: 700; }
.monthly-sep      { color: var(--p-text-muted-color); }
.monthly-capacity { font-size: 1rem; color: var(--p-text-muted-color); }
.monthly-pct      { font-size: .85rem; font-weight: 700; margin-left: 8px; }

.monthly-bar-track {
    width: 100%; height: 10px;
    background: var(--p-surface-200); border-radius: 6px; overflow: hidden;
}
.monthly-bar-fill { height: 100%; border-radius: 6px; transition: width .4s ease; }

.monthly-footer {
    display: flex; justify-content: space-between; margin-top: 6px;
    font-size: .78rem; color: var(--p-text-muted-color);
}
.monthly-done { color: var(--p-green-600); font-weight: 600; }

/* ── Stat cards ─────────────────────────────────────────────── */
.stats-row {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px;
}
.stat { text-align: center; }
.stat-label-row { display: flex; align-items: center; justify-content: center; gap: 4px; margin-bottom: 4px; }
.stat-lbl  { font-size: .75rem; color: var(--p-text-muted-color); }
.stat-info { font-size: .7rem; color: var(--p-text-muted-color); cursor: help; }
.stat-num           { display: block; font-size: 2rem; font-weight: 700; color: var(--p-text-color); }
.stat-num.delivered { color: var(--p-green-500); }
.stat-num.active    { color: var(--p-green-500); }
.stat-num.read      { color: var(--p-primary-color); }
.stat-num.failed    { color: var(--p-red-500); }
.stat-num.opted-out { color: var(--p-orange-500); }

/* ── Chart ──────────────────────────────────────────────────── */
.chart-loading { text-align: center; padding: 48px; color: var(--p-text-muted-color); font-size: .85rem; }
.send-chart    { height: 240px; }

/* ── Histórico ──────────────────────────────────────────────── */
.history-table {
    width: 100%; border-collapse: collapse; font-size: .85rem;
}
.history-table th {
    text-align: left; font-size: .75rem; font-weight: 600;
    color: var(--p-text-muted-color); padding: 4px 8px 8px; border-bottom: 1px solid var(--p-surface-200);
}
.history-table td { padding: 7px 8px; border-bottom: 1px solid var(--p-surface-100); }
.history-table .num-col { text-align: right; font-variant-numeric: tabular-nums; }
.history-table .bar-col { width: 120px; }
.month-cell { font-weight: 500; display: flex; align-items: center; gap: 6px; }
.current-badge {
    font-size: .68rem; background: var(--p-primary-100); color: var(--p-primary-700);
    border-radius: 4px; padding: 1px 5px; font-weight: 600;
}
.row-current { background: var(--p-surface-50); }
.muted { color: var(--p-text-muted-color); }

.mini-bar-track { width: 100%; height: 7px; background: var(--p-surface-200); border-radius: 4px; overflow: hidden; }
.mini-bar-fill  { height: 100%; border-radius: 4px; transition: width .4s ease; }

/* ── Colores de avance ──────────────────────────────────────── */
.pct-done { color: var(--p-green-600); }
.pct-ok   { color: var(--p-primary-600); }
.pct-warn { color: var(--p-yellow-700); }
.pct-low  { color: var(--p-red-500); }

.pct-done.monthly-bar-fill, .pct-done.mini-bar-fill { background: var(--p-green-500); }
.pct-ok.monthly-bar-fill,   .pct-ok.mini-bar-fill   { background: var(--p-primary-500); }
.pct-warn.monthly-bar-fill, .pct-warn.mini-bar-fill { background: var(--p-yellow-500); }
.pct-low.monthly-bar-fill,  .pct-low.mini-bar-fill  { background: var(--p-red-400); }

/* ── Mensajes tabla ─────────────────────────────────────────── */
.card-title-row   { display: flex; justify-content: space-between; align-items: center; width: 100%; }
.title-actions    { display: flex; gap: 2px; }
.empty-msg { color: var(--p-text-muted-color); font-size: .85rem; }
.muted-cell { color: var(--p-text-muted-color); font-size: .82rem; }
.ch-wa  { color: #25d366; font-size: 1.05rem; }
.ch-sms { color: var(--p-blue-500); font-size: 1.05rem; }
.mt-2      { margin-top: 8px; }

.logs-filter-row { display: flex; gap: 8px; margin-bottom: 8px; }
.logs-pagination { display: flex; align-items: center; gap: 4px; margin-top: 8px; font-size: .82rem; }
.logs-page-info  { padding: 0 4px; }
.logs-total      { color: var(--p-text-muted-color); margin-left: 8px; }

@media (max-width: 900px) {
    .stats-row { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 480px) {
    .stats-row { gap: 10px; }
    .stat-num  { font-size: 1.5rem; }
}
</style>
