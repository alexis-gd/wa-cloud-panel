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
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import Card        from 'primevue/card';
import Button      from 'primevue/button';
import Password    from 'primevue/password';
import Tag         from 'primevue/tag';
import Message     from 'primevue/message';
import InputNumber from 'primevue/inputnumber';
import Select      from 'primevue/select';
import { api }     from '../api.js';

const tokenStatus = ref(null);
const newToken    = ref('');
const saving      = ref(false);
const saveResult  = ref(null);

const cooldownDays   = ref(null);
const savingCooldown = ref(false);
const cooldownResult = ref(null);

const assignmentMode  = ref('least_chats');
const savingMode      = ref(false);
const modeResult      = ref(null);
const assignmentModes = [
    { label: 'Menos chats activos',    value: 'least_chats' },
    { label: 'Primer disponible',      value: 'first_available' },
];

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

async function saveCooldown() {
    savingCooldown.value = true;
    cooldownResult.value = null;

    cooldownResult.value = await api.updateCooldown(cooldownDays.value);
    savingCooldown.value = false;
}

onMounted(() => {
    loadStatus();
    loadCooldown();
    loadAssignmentMode();
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
</style>
