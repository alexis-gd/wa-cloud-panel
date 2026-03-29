<template>
    <div class="login-page">
        <div class="login-card">
            <div class="login-brand">
                <i class="pi pi-whatsapp" />
                <span>WA Cloud Panel</span>
            </div>

            <h2 class="login-title">Iniciar sesión</h2>

            <div class="login-form">
                <div class="field">
                    <label>Correo electrónico</label>
                    <InputText
                        v-model="email"
                        type="email"
                        placeholder="usuario@prestamas.mx"
                        fluid
                        :disabled="loading"
                        @keyup.enter="submit"
                    />
                </div>

                <div class="field">
                    <label>Contraseña</label>
                    <Password
                        v-model="password"
                        :feedback="false"
                        toggleMask
                        fluid
                        :disabled="loading"
                        @keyup.enter="submit"
                    />
                </div>

                <div v-if="error" class="login-error">
                    <i class="pi pi-exclamation-circle" />
                    {{ error }}
                </div>

                <Button
                    label="Entrar"
                    icon="pi pi-sign-in"
                    :loading="loading"
                    :disabled="!email || !password"
                    fluid
                    @click="submit"
                />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import InputText from 'primevue/inputtext';
import Password  from 'primevue/password';
import Button    from 'primevue/button';
import { api }   from '../api.js';
import { useAuth } from '../auth.js';

const router   = useRouter();
const { setUser } = useAuth();

const email    = ref('');
const password = ref('');
const loading  = ref(false);
const error    = ref('');

async function submit() {
    if (!email.value || !password.value) return;

    loading.value = true;
    error.value   = '';

    const res = await api.login(email.value, password.value);
    loading.value = false;

    if (res.status === 'ok') {
        localStorage.setItem('wa_token', res.data.token);
        setUser(res.data.user);
        router.push('/');
    } else {
        error.value = res.message ?? 'Error al iniciar sesión.';
    }
}
</script>

<style scoped>
.login-page {
    min-height: 100vh;
    background: #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
}

.login-card {
    background: #ffffff;
    border-radius: 16px;
    padding: 40px 36px;
    width: 100%;
    max-width: 400px;
    box-shadow: 0 4px 24px rgba(0,0,0,.08);
}

.login-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 700;
    font-size: 1.1rem;
    color: #10b981;
    margin-bottom: 28px;
}

.login-brand .pi { font-size: 1.6rem; }

.login-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 24px;
}

.login-form { display: flex; flex-direction: column; gap: 16px; }

.field { display: flex; flex-direction: column; gap: 6px; }
.field label { font-size: .85rem; font-weight: 600; color: #374151; }

.login-error {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: #fef2f2;
    border-radius: 8px;
    color: #dc2626;
    font-size: .85rem;
}

.login-error .pi { font-size: .9rem; }
</style>
