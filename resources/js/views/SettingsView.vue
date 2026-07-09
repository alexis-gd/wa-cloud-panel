<template>
    <div class="settings">
        <Card style="max-width: 560px">
            <template #title>Token de acceso WhatsApp</template>
            <template #content>
                <div class="token-status" v-if="tokenStatus">
                    <Tag
                        :value="tokenStatus.token_valid ? 'Token válido - ' + tokenStatus.token_user : 'Token inválido'"
                        :severity="tokenStatus.token_valid ? 'success' : 'danger'"
                        :icon="tokenStatus.token_valid ? 'pi pi-check-circle' : 'pi pi-times-circle'"
                    />
                    <span v-if="!tokenStatus.token_valid" class="token-error">
                        {{ tokenStatus.token_error }}
                    </span>
                </div>

                <form class="token-form" @submit.prevent="saveToken" autocomplete="off">
                    <div class="form-group">
                        <label>Pegar nuevo token</label>
                        <Password
                            v-model="newToken"
                            placeholder="EAAUz..."
                            :feedback="false"
                            toggle-mask
                            fluid
                            input-class="token-input"
                            :input-props="{ autocomplete: 'one-time-code', autocorrect: 'off', autocapitalize: 'off', spellcheck: false, name: 'wa-token-paste' }"
                        />
                        <small>El token temporal dura ~24h. Para producción usa un System User Token (no expira).</small>
                    </div>

                    <Button
                        type="submit"
                        label="Guardar token"
                        icon="pi pi-save"
                        :loading="saving"
                        :disabled="!newToken"
                    />
                </form>

                <Message v-if="saveResult" :severity="saveResult.error ? 'error' : 'success'" class="mt-3">
                    {{ saveResult.error ?? saveResult.message }}
                </Message>
            </template>
        </Card>
        <Card style="max-width: 560px; margin-top: 24px">
            <template #title>Números de WhatsApp</template>
            <template #content>
                <p class="pn-help">
                    Da de alta los números que envían campañas. Primero regístralos en Meta
                    (Business Manager); aquí ingresas su ID y el sistema los verifica contra Meta
                    antes de guardarlos. Usa el token de la cuenta que ya configuraste arriba (no
                    lo vuelves a escribir) y el límite diario lo pone Meta.
                </p>

                <ul v-if="phoneNumbers.length" class="pn-list">
                    <li v-for="n in phoneNumbers" :key="n.id" class="pn-item">
                        <div class="pn-info">
                            <span class="pn-name">{{ n.display_name }}</span>
                            <span class="pn-meta">
                                {{ n.daily_limit }} msj/día
                                <template v-if="n.quality_rating"> · calidad {{ n.quality_rating }}</template>
                            </span>
                        </div>
                        <Tag v-if="n.is_paused" value="Pausado" severity="danger" />
                        <Tag v-else :value="n.is_active ? 'Activo' : 'Inactivo'" :severity="n.is_active ? 'success' : 'secondary'" />
                        <div class="pn-actions">
                            <Button icon="pi pi-verified" text size="small" title="Verificar con Meta" :loading="verifyingId === n.id" @click="verifyNumber(n)" />
                            <Button :icon="n.is_active ? 'pi pi-pause' : 'pi pi-play'" text size="small" :title="n.is_active ? 'Desactivar' : 'Activar'" @click="toggleActive(n)" />
                        </div>
                    </li>
                </ul>
                <p v-else class="pn-empty">Aún no hay números dados de alta.</p>

                <Message v-if="verifyResult" :severity="verifyResult.error ? 'error' : 'success'" class="mt-3">
                    {{ verifyResult.error ?? verifyResult.message }}
                </Message>

                <form class="pn-form" @submit.prevent="addNumber" autocomplete="off">
                    <div class="form-group">
                        <label>Nombre para identificarlo</label>
                        <InputText v-model="pnForm.display_name" placeholder="Número campañas 1" fluid />
                    </div>
                    <div class="form-group">
                        <label>Phone number ID (de Meta)</label>
                        <InputText v-model="pnForm.phone_number_id" placeholder="1082360764952377" inputmode="numeric" :invalid="!!phoneIdError" fluid />
                        <small v-if="phoneIdError" class="pn-field-error">{{ phoneIdError }}</small>
                        <small v-else>Solo números, tal como aparece en Meta (business.facebook.com).</small>
                    </div>
                    <div class="form-group">
                        <label>WABA ID (de Meta)</label>
                        <InputText v-model="pnForm.waba_id" placeholder="1236630511398211" inputmode="numeric" :invalid="!!wabaIdError" fluid />
                        <small v-if="wabaIdError" class="pn-field-error">{{ wabaIdError }}</small>
                        <small v-else>Solo números. Es el ID de la cuenta de WhatsApp Business.</small>
                    </div>
                    <Button type="submit" label="Verificar y guardar" icon="pi pi-plus" :loading="addingNumber" :disabled="!pnFormValid" />
                </form>

                <Message v-if="addResult" :severity="addResult.error ? 'error' : 'success'" class="mt-3">
                    {{ addResult.error ?? 'Número dado de alta y verificado.' }}
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
                <!-- Control desactivado a propósito (ver stageControlEnabled). Se mantiene todo el
                     código intacto para reactivarlo más adelante poniendo la constante en true. -->
                <template v-if="stageControlEnabled">
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

                <div v-else class="stage-disabled">
                    <i class="pi pi-lock stage-disabled-icon"></i>
                    <div>
                        <p class="stage-disabled-title">Módulo desactivado temporalmente</p>
                    </div>
                </div>
            </template>
        </Card>
        <Card style="max-width: 560px; margin-top: 24px">
            <template #title>Control de envíos</template>
            <template #content>
                <div class="form-group">
                    <label>Días de enfriamiento entre mensajes al mismo contacto</label>
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
                    {{ cooldownResult.error ?? 'Enfriamiento actualizado a ' + cooldownResult.data?.cooldown_days + ' días.' }}
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
                        Un <b>rebote</b> es un SMS que no se pudo entregar: número apagado o sin señal
                        por mucho tiempo, línea fija, número inexistente o bloqueado por la operadora
                        (el gateway lo reporta como fallido). "Seguidos" = rebotes uno tras otro sin
                        ninguna entrega exitosa en medio; una entrega exitosa reinicia el contador.
                        <br><br>
                        <b>0 = desactivado</b> (default): los rebotes se cuentan para reporte pero nunca
                        bloquean el número. Un valor mayor bloquea el SMS tras esa cantidad de fallos seguidos.
                        No afecta WhatsApp ni la baja por STOP (STOP siempre bloquea).
                    </small>
                </div>

                <Message v-if="bouncesResult" :severity="bouncesResult.error ? 'error' : 'success'" class="mt-3">
                    {{ bouncesResult.error ?? 'Umbral de auto-baja SMS actualizado.' }}
                </Message>
            </template>
        </Card>
        <Card style="max-width: 560px; margin-top: 24px">
            <template #title>
                <span class="wh-title">
                    Salud del webhook SMS
                    <Button icon="pi pi-refresh" text rounded size="small" :loading="loadingWh" @click="loadWebhookHealth" />
                </span>
            </template>
            <template #content>
                <p class="stage-desc">
                    Confirma si el teléfono del gateway le está mandando al panel las entregas y las
                    respuestas de SMS.
                </p>

                <div v-if="webhookHealth" class="wh-row">
                    <Tag :value="whDiag.label" :severity="whDiag.severity" :icon="whDiag.icon" />
                </div>
                <p v-if="webhookHealth" class="wh-msg">{{ whDiag.msg }}</p>

                <div v-if="webhookHealth" class="wh-stats">
                    <div><span class="wh-muted">Última llegada:</span> {{ webhookHealth.last_hit_at ? `${webhookHealth.last_hit_at} (${agoLabel(webhookHealth.last_hit_ago)})` : 'nunca' }}</div>
                    <div><span class="wh-muted">Último OK:</span> {{ webhookHealth.last_at ? `${webhookHealth.last_event} · ${webhookHealth.last_at} (${agoLabel(webhookHealth.last_at_ago)})` : 'nunca' }}</div>
                    <div v-if="webhookHealth.last_rejected_at"><span class="wh-muted">Último rechazo (firma):</span> {{ webhookHealth.last_rejected_at }} ({{ agoLabel(webhookHealth.last_rejected_ago) }})</div>
                </div>

                <div class="wh-help">
                    <p class="wh-help-title"><i class="pi pi-info-circle"></i> ¿No están entrando ni saliendo SMS?</p>
                    <ol class="wh-help-list">
                        <li>Revisa el <b>teléfono del gateway</b>: que esté <b>encendido</b> y con <b>internet</b> (WiFi o datos).</li>
                        <li>Abre la <b>app del gateway</b> en el teléfono y confirma que aparezca <b>en línea</b> (no "desconectado").</li>
                        <li>Si sigue igual, avisa al <b>soporte técnico</b>.</li>
                    </ol>
                </div>
            </template>
        </Card>
        <Card style="max-width: 560px; margin-top: 24px" class="demo-card">
            <template #title>Modo demo</template>
            <template #content>
                <p class="stage-desc">
                    Quita el circuit breaker del número, resetea el enfriamiento de todos los contactos,
                    limpia las bajas de SMS (baja por STOP, bloqueo, inválido) y reactiva las bajas de
                    WhatsApp (vuelve a Activo a los que se dieron de baja) para que el número de prueba
                    pueda volver a recibir. Úsalo antes de una demostración.
                </p>
                <Button
                    label="Resetear para demo"
                    icon="pi pi-refresh"
                    severity="danger"
                    :loading="resettingDemo"
                    @click="confirmDemoReset"
                />
                <Message v-if="demoResetResult" :severity="demoResetResult.error ? 'error' : 'success'" class="mt-3">
                    {{ demoResetResult.error ?? 'Listo - enfriamiento reseteado, circuit breaker quitado, bajas de SMS limpiadas y bajas de WhatsApp reactivadas.' }}
                </Message>
            </template>
        </Card>
        <ConfirmDialog />
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import Card          from 'primevue/card';
import Button        from 'primevue/button';
import Password      from 'primevue/password';
import Tag           from 'primevue/tag';
import Message       from 'primevue/message';
import InputNumber   from 'primevue/inputnumber';
import InputText     from 'primevue/inputtext';
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

// ── Números de WhatsApp ──
const phoneNumbers  = ref([]);
const pnForm        = ref({ display_name: '', phone_number_id: '', waba_id: '' });
const addingNumber  = ref(false);
const addResult     = ref(null);
const verifyingId   = ref(null);
const verifyResult  = ref(null);

// Los IDs de Meta son numéricos (5-20 dígitos). Validamos en el front para no gastar un
// request ni una llamada a Meta si el dato viene mal.
const isMetaId    = (v) => /^\d{5,20}$/.test(String(v ?? '').trim());
const phoneIdError = computed(() =>
    pnForm.value.phone_number_id && !isMetaId(pnForm.value.phone_number_id)
        ? 'Debe ser solo números (5 a 20 dígitos).' : '',
);
const wabaIdError = computed(() =>
    pnForm.value.waba_id && !isMetaId(pnForm.value.waba_id)
        ? 'Debe ser solo números (5 a 20 dígitos).' : '',
);

// El token no se pide: se reutiliza el de la cuenta (número activo / misma WABA). El límite
// diario tampoco: lo dicta Meta.
const pnFormValid = computed(() =>
    !!pnForm.value.display_name &&
    isMetaId(pnForm.value.phone_number_id) &&
    isMetaId(pnForm.value.waba_id),
);

const cooldownDays   = ref(null);
const savingCooldown = ref(false);
const cooldownResult = ref(null);

const webhookHealth = ref(null);
const loadingWh     = ref(false);

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

// Interruptor del bloque "Etapas de entrega". Apagado a propósito: el control de
// feature flags (presets + toggles) queda oculto para que nadie apague módulos por error.
// Ya cumplió su ciclo (todos los módulos activos). Poner en true para reactivarlo más adelante.
const stageControlEnabled = false;

const flagModules = [
    {
        key: 'feature_dashboard', label: 'Panel',
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

async function loadPhoneNumbers() {
    const res = await api.phoneNumbers();
    if (res.status === 'ok') phoneNumbers.value = res.data;
}

async function addNumber() {
    addingNumber.value = true;
    addResult.value    = null;
    const res = await api.addPhoneNumber({ ...pnForm.value });
    addResult.value    = res.status === 'ok' ? res : { error: res.message };
    addingNumber.value = false;
    if (res.status === 'ok') {
        pnForm.value = { display_name: '', phone_number_id: '', waba_id: '' };
        await loadPhoneNumbers();
    }
}

async function verifyNumber(n) {
    verifyingId.value  = n.id;
    verifyResult.value = null;
    const res = await api.verifyPhoneNumber(n.id);
    if (res.status === 'ok') {
        const d = res.data;
        verifyResult.value = { message: `${n.display_name}: ${d.display_phone_number ?? ''} · verificación ${d.code_verification_status ?? '-'} · nombre ${d.name_status ?? '-'} · calidad ${d.quality_rating ?? '-'}` };
        n.quality_rating = d.quality_rating;
    } else {
        verifyResult.value = { error: res.message };
    }
    verifyingId.value = null;
}

async function toggleActive(n) {
    const res = await api.updatePhoneNumber(n.id, { is_active: !n.is_active });
    if (res.status === 'ok') n.is_active = res.data.is_active;
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
        message: '¿Quitar el circuit breaker, resetear el enfriamiento, limpiar las bajas de SMS y reactivar las bajas de WhatsApp de todos los contactos?',
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

async function loadWebhookHealth() {
    loadingWh.value = true;
    const res = await api.smsWebhookHealth();
    if (res.status === 'ok') webhookHealth.value = res.data;
    loadingWh.value = false;
}

const whDiag = computed(() => {
    const d = webhookHealth.value?.diagnosis;
    return {
        ok: {
            label: 'Recibiendo eventos', severity: 'success', icon: 'pi pi-check-circle',
            msg: 'El gateway está entregando eventos y el panel los procesa. Todo bien.',
        },
        signature: {
            label: 'Rechazando por firma', severity: 'danger', icon: 'pi pi-times-circle',
            msg: 'Llegan eventos pero se rechazan por firma: el SMS_WEBHOOK_SECRET del panel no coincide con la Signing Key del teléfono. Iguala ambos (o deja el secret vacío en el panel para no validar) y reinicia la app del teléfono una vez.',
        },
        no_hits: {
            label: 'Sin llegadas', severity: 'danger', icon: 'pi pi-times-circle',
            msg: 'El gateway no está mandando ningún evento al panel. Verifica que el webhook esté registrado en el gateway, que la URL apunte al panel y que el teléfono esté conectado.',
        },
        stale: {
            label: 'Sin eventos recientes', severity: 'warn', icon: 'pi pi-exclamation-triangle',
            msg: 'Hubo eventos antes pero hace mucho que no llega uno válido. El gateway o el teléfono pudieron dejar de mandar.',
        },
    }[d] ?? { label: 'Sin datos', severity: 'secondary', icon: 'pi pi-question-circle', msg: 'Aún no hay información del webhook.' };
});

function agoLabel(min) {
    if (min === null || min === undefined) return '';
    if (min < 1)   return 'hace segundos';
    if (min < 60)  return `hace ${min} min`;
    const h = Math.floor(min / 60);
    if (h < 24)    return `hace ${h} h`;
    return `hace ${Math.floor(h / 24)} d`;
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
    loadPhoneNumbers();
    loadCooldown();
    loadAssignmentMode();
    loadFlags();
    loadSmsBounces();
    loadWebhookHealth();
});
</script>

<style scoped>
.token-status { margin-bottom: 16px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.token-form   { display: flex; flex-direction: column; align-items: flex-start; gap: 14px; }
.token-error  { font-size: .82rem; color: var(--p-red-600); }
.form-group   { margin-bottom: 16px; }
.form-group label { display: block; font-size: .82rem; color: var(--p-text-muted-color); margin-bottom: 6px; }
.form-group small { display: block; font-size: .78rem; color: var(--p-text-muted-color); margin-top: 6px; }
.mt-3         { margin-top: 12px; }
.pn-help  { font-size: .82rem; color: var(--p-text-muted-color); margin-bottom: 14px; }
.pn-list  { list-style: none; padding: 0; margin: 0 0 16px; display: flex; flex-direction: column; gap: 8px; }
.pn-item  { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: var(--p-surface-100); border-radius: 8px; }
.pn-info  { display: flex; flex-direction: column; flex: 1; min-width: 0; }
.pn-name  { font-weight: 600; font-size: .9rem; }
.pn-meta  { font-size: .78rem; color: var(--p-text-muted-color); }
.pn-actions { display: flex; gap: 2px; }
.pn-empty { font-size: .85rem; color: var(--p-text-muted-color); margin-bottom: 16px; }
.pn-form  { display: flex; flex-direction: column; gap: 4px; border-top: 1px solid var(--p-surface-200); padding-top: 16px; }
.pn-field-error { color: var(--p-red-600) !important; }
.cooldown-row { display: flex; align-items: center; gap: 12px; margin-bottom: 8px; }
.stage-desc   { font-size: .82rem; color: var(--p-text-muted-color); margin-bottom: 14px; }
.preset-btns  { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; }
.flags-modules    { display: flex; flex-direction: column; gap: 12px; }
.flag-row         { display: flex; align-items: center; gap: 10px; }
.flag-row--sub    { margin-left: 28px; margin-top: 6px; }
.flag-label       { font-size: .88rem; }
.flag-label--sub  { font-size: .82rem; color: var(--p-text-muted-color); }
.demo-card :deep(.p-card-title) { color: var(--p-red-600); }
.wh-title  { display: flex; align-items: center; gap: 8px; }
.wh-row    { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
.wh-msg    { font-size: .82rem; color: var(--p-text-color); margin: 10px 0 0; line-height: 1.5; }
.wh-stats  { margin-top: 12px; display: flex; flex-direction: column; gap: 4px; font-size: .8rem; }
.wh-muted  { color: var(--p-text-muted-color); font-size: .78rem; }

.wh-help        { margin-top: 16px; padding: 12px 14px; background: var(--p-surface-50); border-radius: 8px; }
.wh-help-title  { font-size: .82rem; font-weight: 600; margin: 0 0 8px; display: flex; align-items: center; gap: 6px; }
.wh-help-list   { margin: 0; padding-left: 18px; font-size: .8rem; color: var(--p-text-color); line-height: 1.5; display: flex; flex-direction: column; gap: 4px; }

.stage-disabled       { display: flex; align-items: flex-start; gap: 12px; padding: 6px 2px; }
.stage-disabled-icon  { font-size: 1.1rem; color: var(--p-text-muted-color); margin-top: 2px; }
.stage-disabled-title { font-size: .88rem; font-weight: 600; margin: 0 0 4px; }
.stage-disabled-text  { font-size: .82rem; color: var(--p-text-muted-color); margin: 0; line-height: 1.5; }
</style>
