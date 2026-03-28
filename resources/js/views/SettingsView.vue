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
    </div>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import Card     from 'primevue/card';
import Button   from 'primevue/button';
import Password from 'primevue/password';
import Tag      from 'primevue/tag';
import Message  from 'primevue/message';
import { api }  from '../api.js';

const tokenStatus = ref(null);
const newToken    = ref('');
const saving      = ref(false);
const saveResult  = ref(null);

async function loadStatus() {
    tokenStatus.value = await api.tokenStatus();
}

async function saveToken() {
    saving.value     = true;
    saveResult.value = null;

    saveResult.value = await api.updateToken(newToken.value);
    saving.value     = false;
    newToken.value   = '';
    await loadStatus();
}

onMounted(loadStatus);
</script>

<style scoped>
.token-status { margin-bottom: 16px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.token-error  { font-size: .82rem; color: var(--p-red-600); }
.form-group   { margin-bottom: 16px; }
.form-group label { display: block; font-size: .82rem; color: var(--p-text-muted-color); margin-bottom: 6px; }
.form-group small { display: block; font-size: .78rem; color: var(--p-text-muted-color); margin-top: 6px; }
.mt-3 { margin-top: 12px; }
</style>
