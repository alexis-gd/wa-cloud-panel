<template>
    <div class="settings">
        <Card style="max-width: 560px">
            <template #title>Token de acceso WhatsApp</template>
            <template #content>
                <div class="token-status" v-if="tokenStatus">
                    <Tag
                        :value="tokenStatus.token_valid ? 'Token válido — ' + tokenStatus.token_user : 'Token inválido'"
                        :severity="tokenStatus.token_valid ? 'success' : 'danger'"
                        :icon="tokenStatus.token_valid ? 'pi pi-check-circle' : 'pi pi-times-circle'"
                    />
                    <span v-if="!tokenStatus.token_valid" class="token-error">
                        {{ tokenStatus.token_error }}
                    </span>
                </div>

                <div class="form-group">
                    <label>Pegar nuevo token</label>
                    <Password
                        v-model="newToken"
                        placeholder="EAAUz..."
                        :feedback="false"
                        toggle-mask
                        fluid
                        input-class="token-input"
                    />
                    <small>El token temporal dura ~24h. Para producción usa un System User Token (no expira).</small>
                </div>

                <Button
                    label="Guardar token"
                    icon="pi pi-save"
                    :loading="saving"
                    :disabled="!newToken"
                    @click="saveToken"
                />

                <Message v-if="saveResult" :severity="saveResult.error ? 'error' : 'success'" class="mt-3">
                    {{ saveResult.error ?? saveResult.message }}
                </Message>
            </template>
        </Card>
        <Card style="max-width: 560px; margin-top: 24px">
            <template #title>Multi-agente — asignación automática</template>
            <template #content>
                <div class="form-group">
                    <label>Modo de asignación al llegar un mensaje nuevo</label>
                    <Select
                        v-model="assignmentMode"
                        :options="assignmentModes"
                        option-label="label"
                        option-value="value"
                        fluid
                        style="max-width: 320px"
                    />
                    <small>
                        <b>Menos chats</b>: asigna al agente con menos conversaciones activas.<br>
                        <b>Primer disponible</b>: asigna al primer agente activo en la lista.
                    </small>
                </div>
                <Button
                    label="Guardar"
                    icon="pi pi-save"
                    :loading="savingMode"
                    @click="saveAssignmentMode"
                />
                <Message v-if="modeResult" :severity="modeResult.error ? 'error' : 'success'" class="mt-3">
                    {{ modeResult.error ?? 'Modo de asignación guardado.' }}
                </Message>
            </template>
        </Card>
        <Card style="max-width: 560px; margin-top: 24px">
            <template #title>Etapas de entrega</template>
            <template #content>
                <p class="stage-desc">Activa las funciones disponibles según la entrega pactada con el cliente.</p>

                <div class="preset-btns">
                    <Button label="E1 — Contactos" severity="secondary" size="small" @click="applyPreset(1)" :loading="savingFlags" />
                    <Button label="E2 — Demo WA"   severity="secondary" size="small" @click="applyPreset(2)" :loading="savingFlags" />
                    <Button label="E3 — Métricas"  severity="secondary" size="small" @click="applyPreset(3)" :loading="savingFlags" />
                    <Button label="E4 — Completo"  severity="secondary" size="small" @click="applyPreset(4)" :loading="savingFlags" />
                </div>

                <div class="flags-modules">
                    <div v-for="mod in flagModules" :key="mod.key">
                        <div class="flag-row">
                            <ToggleSwitch v-model="flags[mod.key]" @update:modelValue="saveFlags" />
                            <span class="flag-label">{{ mod.label }}</span>
                        </div>
                        <div v-for="sub in mod.subs" :key="sub.key" class="flag-row flag-row--sub">
                            <ToggleSwitch v-model="flags[sub.key]" @update:modelValue="saveFlags" />
                            <span class="flag-label flag-label--sub">{{ sub.label }}</span>
                        </div>
                    </div>
                </div>

                <Message v-if="flagsResult" :severity="flagsResult.error ? 'error' : 'success'" class="mt-3">
                    {{ flagsResult.error ?? 'Funciones actualizadas.' }}
                </Message>
            </template>
        </Card>
        <Card style="max-width: 560px; margin-top: 24px">
            <template #title>Control de envíos</template>
            <template #content>
                <div class="form-group">
                    <label>Días de espera entre mensajes al mismo contacto</label>
                    <div class="cooldown-row">
                        <InputNumber
                            v-model="cooldownDays"
                            :min="7"
                            :max="365"
                            show-buttons
                            button-layout="horizontal"
                            :step="1"
                            suffix=" días"
                            style="width: 180px"
                        />
                        <Button
                            label="Guardar"
                            icon="pi pi-save"
                            :loading="savingCooldown"
                            :disabled="cooldownDays === null"
                            @click="saveCooldown"
                        />
                    </div>
                    <small>Mínimo 7 días. Un contacto que ya recibió un mensaje no recibirá otro hasta que pase este período.</small>
                </div>

                <Message v-if="cooldownResult" :severity="cooldownResult.error ? 'error' : 'success'" class="mt-3">
                    {{ cooldownResult.error ?? 'Cooldown actualizado a ' + cooldownResult.data?.cooldown_days + ' días.' }}
                </Message>
            </template>
        </Card>
        <Card style="max-width: 560px; margin-top: 24px">
            <template #title>Auto-baja de SMS por rebotes</template>
            <template #content>
                <div class="form-group">
                    <label>Rebotes seguidos antes de bloquear un número para SMS</label>
                    <div class="cooldown-row">
                        <InputNumber
                            v-model="smsBounces"
                            :min="0"
                            :max="20"
                            show-buttons
                            button-layout="horizontal"
                            :step="1"
                            style="width: 180px"
                        />
                        <Button
                            label="Guardar"
                            icon="pi pi-save"
                            :loading="savingBounces"
                            :disabled="smsBounces === null"
                            @click="saveSmsBounces"
                        />
                    </div>
                    <small>
                        <b>0 = desactivado</b> (default): los rebotes se cuentan para reporte pero nunca
                        bloquean el número. Un valor mayor bloquea el SMS tras esa cantidad de fallos seguidos.
                        No afecta WhatsApp ni el opt-out (STOP siempre bloquea).
                    </small>
                </div>

                <Message v-if="bouncesResult" :severity="bouncesResult.error ? 'error' : 'success'" class="mt-3">
                    {{ bouncesResult.error ?? 'Umbral de auto-baja SMS actualizado.' }}
                </Message>
            </template>
        </Card>
        <Card style="max-width: 560px; margin-top: 24px" class="demo-card">
            <template #title>Modo demo</template>
            <template #content>
                <p class="stage-desc">
                    Quita el circuit breaker del número, resetea el cooldown de todos los contactos
                    y limpia las bajas de SMS (opt-out, bloqueo, inválido) para que el número de
                    prueba pueda volver a recibir. Úsalo antes de una demostración.
                </p>
                <Button
                    label="Resetear para demo"
                    icon="pi pi-refresh"
                    severity="danger"
                    :loading="resettingDemo"
                    @click="confirmDemoReset"
                />
                <Message v-if="demoResetResult" :severity="demoResetResult.error ? 'error' : 'success'" class="mt-3">
                    {{ demoResetResult.error ?? 'Listo - cooldown reseteado, circuit breaker quitado y bajas de SMS limpiadas.' }}
                </Message>
            </template>
        </Card>
        <ConfirmDialog />
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import Card          from 'primevue/card';
import Button        from 'primevue/button';
import Password      from 'primevue/password';
import Tag           from 'primevue/tag';
import Message       from 'primevue/message';
import InputNumber   from 'primevue/inputnumber';
import Select        from 'primevue/select';
import ToggleSwitch  from 'primevue/toggleswitch';
import ConfirmDialog from 'primevue/confirmdialog';
import { useConfirm } from 'primevue/useconfirm';
import { api }       from '../api.js';

const confirm = useConfirm();

const resettingDemo  = ref(false);
const demoResetResult = ref(null);

const tokenStatus = ref(null);
const newToken    = ref('');
const saving      = ref(false);
const saveResult  = ref(null);

const cooldownDays   = ref(null);
const savingCooldown = ref(false);
const cooldownResult = ref(null);

const smsBounces    = ref(0);
const savingBounces = ref(false);
const bouncesResult = ref(null);

const assignmentMode  = ref('least_chats');
const savingMode      = ref(false);
const modeResult      = ref(null);
const assignmentModes = [
    { label: 'Menos chats activos',    value: 'least_chats' },
    { label: 'Primer disponible',      value: 'first_available' },
];

const flagModules = [
    {
        key: 'feature_dashboard', label: 'Dashboard',
        subs: [
            { key: 'feature_daily_chart', label: 'Gráfica diaria' },
        ],
    },
    {
        key: 'feature_contacts', label: 'Contactos',
        subs: [
            { key: 'feature_export', label: 'Exportar reportes' },
            { key: 'feature_tags',   label: 'Tags y segmentación' },
        ],
    },
    { key: 'feature_campaigns',     label: 'Campañas',        subs: [] },
    { key: 'feature_templates',     label: 'Plantillas',       subs: [] },
    { key: 'feature_users',         label: 'Usuarios',         subs: [] },
    {
        key: 'feature_conversations', label: 'Conversaciones',
        subs: [
            { key: 'feature_multi_agent', label: 'Multi-agente' },
        ],
    },
];
const flags       = ref({});
const savingFlags = ref(false);
const flagsResult = ref(null);

const PRESETS = {
    1: {
        feature_dashboard: true,  feature_contacts: true,
        feature_campaigns: false, feature_templates: false, feature_users: false, feature_conversations: false,
        feature_daily_chart: false, feature_export: false, feature_tags: false, feature_multi_agent: false,
    },
    2: {
        feature_dashboard: true,  feature_contacts: true,
        feature_campaigns: true,  feature_templates: true,  feature_users: true,  feature_conversations: false,
        feature_daily_chart: false, feature_export: false, feature_tags: false, feature_multi_agent: false,
    },
    3: {
        feature_dashboard: true,  feature_contacts: true,
        feature_campaigns: true,  feature_templates: true,  feature_users: true,  feature_conversations: false,
        feature_daily_chart: true,  feature_export: true,  feature_tags: true,  feature_multi_agent: false,
    },
    4: {
        feature_dashboard: true,  feature_contacts: true,
        feature_campaigns: true,  feature_templates: true,  feature_users: true,  feature_conversations: true,
        feature_daily_chart: true,  feature_export: true,  feature_tags: true,  feature_multi_agent: true,
    },
};

async function loadFlags() {
    const res = await api.getFeatures();
    if (res.status === 'ok') flags.value = { ...res.data };
}

async function saveFlags() {
    savingFlags.value = true;
    flagsResult.value = null;
    const res = await api.updateFeatures(flags.value);
    flagsResult.value = res.status === 'ok' ? { ok: true } : { error: res.message };
    savingFlags.value = false;
}

async function applyPreset(stage) {
    flags.value = { ...PRESETS[stage] };
    await saveFlags();
}

async function loadStatus() {
    tokenStatus.value = await api.tokenStatus();
}

async function loadCooldown() {
    const res = await api.getCooldown();
    if (res.status === 'ok') cooldownDays.value = res.data.cooldown_days;
}

async function loadAssignmentMode() {
    const res = await api.getAssignmentMode();
    if (res.status === 'ok') assignmentMode.value = res.data.assignment_mode;
}

async function saveAssignmentMode() {
    savingMode.value  = true;
    modeResult.value  = null;
    modeResult.value  = await api.updateAssignmentMode(assignmentMode.value);
    savingMode.value  = false;
}

async function saveToken() {
    saving.value     = true;
    saveResult.value = null;

    saveResult.value = await api.updateToken(newToken.value);
    saving.value     = false;
    newToken.value   = '';
    await loadStatus();
}

function confirmDemoReset() {
    confirm.require({
        message: '¿Quitar el circuit breaker, resetear el cooldown y limpiar las bajas de SMS de todos los contactos?',
        header:  'Resetear para demo',
        icon:    'pi pi-exclamation-triangle',
        acceptLabel: 'Sí, resetear',
        rejectLabel: 'Cancelar',
        accept: async () => {
            resettingDemo.value  = true;
            demoResetResult.value = null;
            const res = await api.demoReset();
            demoResetResult.value = res.status === 'ok' ? res : { error: res.message };
            resettingDemo.value  = false;
        },
    });
}

async function saveCooldown() {
    savingCooldown.value = true;
    cooldownResult.value = null;

    cooldownResult.value = await api.updateCooldown(cooldownDays.value);
    savingCooldown.value = false;
}

async function loadSmsBounces() {
    const res = await api.getSmsAutoBlacklist();
    if (res.status === 'ok') smsBounces.value = res.data.sms_auto_blacklist_bounces;
}

async function saveSmsBounces() {
    savingBounces.value = true;
    bouncesResult.value = null;
    const res = await api.updateSmsAutoBlacklist(smsBounces.value);
    bouncesResult.value = res.status === 'ok' ? { ok: true } : { error: res.message };
    savingBounces.value = false;
}

onMounted(() => {
    loadStatus();
    loadCooldown();
    loadAssignmentMode();
    loadFlags();
    loadSmsBounces();
});
</script>

<style scoped>
.token-status { margin-bottom: 16px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.token-error  { font-size: .82rem; color: var(--p-red-600); }
.form-group   { margin-bottom: 16px; }
.form-group label { display: block; font-size: .82rem; color: var(--p-text-muted-color); margin-bottom: 6px; }
.form-group small { display: block; font-size: .78rem; color: var(--p-text-muted-color); margin-top: 6px; }
.mt-3         { margin-top: 12px; }
.cooldown-row { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
.stage-desc   { font-size: .82rem; color: var(--p-text-muted-color); margin-bottom: 14px; }
.preset-btns  { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
.flags-modules    { display: flex; flex-direction: column; gap: 12px; }
.flag-row         { display: flex; align-items: center; gap: 10px; }
.flag-row--sub    { margin-left: 28px; margin-top: 6px; }
.flag-label       { font-size: .88rem; }
.flag-label--sub  { font-size: .82rem; color: var(--p-text-muted-color); }
.demo-card :deep(.p-card-title) { color: var(--p-red-600); }
</style>
