<template>
    <div class="login-page">
        <div class="login-shell">

            <aside class="login-aside">
                <div class="va-glow" />
                <div class="va-grid" />
                <span class="bubble b1" />
                <span class="bubble b2" />
                <span class="bubble b3" />
                <span class="bubble b4" />
                <span class="bubble b5"><i class="pi pi-send" /></span>

                <div class="aside-brand">
                    <div class="word">
                        <span class="word-text"><span class="wa">Prestamaz</span> <span class="rest">Panel</span></span>
                    </div>
                    <div class="chan-pill"><span>WhatsApp</span><span class="sep" /><span>SMS</span></div>
                </div>

                <div class="aside-copy">
                    <h1>Conversaciones que llegan, a escala.</h1>
                    <p>Envía campañas masivas por WhatsApp y SMS, monitorea entregas en tiempo real y mantén cada
                        conversación en un solo lugar.</p>
                </div>

                <p class="aside-foot">Mensajería masiva multicanal para tu operación. Gestiona WhatsApp y SMS sin salir
                    de Prestamaz Panel.</p>
            </aside>

            <div class="login-main">
                <div class="login-form-wrap">
                    <p class="form-eyebrow">Bienvenido de nuevo</p>
                    <h2 class="form-title">Inicia sesión</h2>
                    <p class="form-sub">Accede para administrar tus campañas y conversaciones.</p>

                    <div class="login-form">
                        <div class="field">
                            <label for="email">Correo electrónico</label>
                            <IconField>
                                <InputIcon class="pi pi-envelope" />
                                <InputText id="email" v-model="email" type="email" placeholder="usuario@prestamas.mx"
                                    fluid :disabled="loading" @keyup.enter="submit" />
                            </IconField>
                        </div>

                        <div class="field">
                            <label for="password">Contraseña</label>
                            <Password input-id="password" v-model="password" :feedback="false" toggle-mask fluid
                                :disabled="loading" @keyup.enter="submit" />
                        </div>

                        <div v-if="error" class="login-error">
                            <i class="pi pi-exclamation-circle" />
                            {{ error }}
                        </div>

                        <Button label="Entrar" icon="pi pi-sign-in" :loading="loading" :disabled="!email || !password"
                            fluid @click="submit" />
                    </div>

                    <div class="form-foot">
                        Prestamaz Panel v0.5.3 <span class="dot">·</span> © {{ year }} Prestamaz
                    </div>
                </div>
            </div>

        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import InputText from 'primevue/inputtext';
import IconField from 'primevue/iconfield';
import InputIcon from 'primevue/inputicon';
import Password from 'primevue/password';
import Button from 'primevue/button';
import { api } from '../api.js';
import { useAuth } from '../auth.js';
import { initFeatures } from '../features.js';

const router = useRouter();
const { setUser } = useAuth();

const email = ref('');
const password = ref('');
const loading = ref(false);
const error = ref('');

const year = new Date().getFullYear();

async function submit() {
    if (!email.value || !password.value) return;

    loading.value = true;
    error.value = '';

    const res = await api.login(email.value, password.value);
    loading.value = false;

    if (res.status === 'ok') {
        localStorage.setItem('wa_token', res.data.token);
        setUser(res.data.user);
        await initFeatures();
        router.push('/');
    } else {
        error.value = res.message ?? 'Error al iniciar sesión.';
    }
}
</script>

<style scoped>
/* ════════ Shell ════════ */
.login-page {
    height: 100vh;
    display: flex;
}

.login-shell {
    display: flex;
    width: 100%;
    height: 100%;
    background: #fff;
}

/* ════════ Panel izquierdo ════════ */
.login-aside {
    position: relative;
    flex: 0 0 45%;
    background: #0f172a;
    color: #fff;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 40px 38px;
}

.aside-brand,
.aside-copy,
.aside-foot {
    position: relative;
    z-index: 3;
}

/* Wordmark */
.word {
    display: flex;
    align-items: center;
    gap: 11px;
}

.word-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 0 4px rgba(16, 185, 129, .18);
}

.word-text {
    font-weight: 800;
    font-size: 1.32rem;
    letter-spacing: -.02em;
    line-height: 1;
}

.word-text .wa {
    color: #34d399;
}

.word-text .rest {
    color: #fff;
}

.chan-pill {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    margin-top: 14px;
    font-size: .62rem;
    font-weight: 600;
    letter-spacing: .18em;
    text-transform: uppercase;
    color: #6ee7b7;
}

.chan-pill .sep {
    width: 3px;
    height: 3px;
    border-radius: 50%;
    background: #6ee7b7;
    opacity: .7;
}

/* Copy */
.aside-copy h1 {
    font-size: 1.9rem;
    font-weight: 700;
    line-height: 1.18;
    letter-spacing: -.02em;
    margin: 0 0 14px;
    color: #fff;
    max-width: 11em;
    text-wrap: balance;
}

.aside-copy p {
    font-size: .95rem;
    line-height: 1.55;
    color: #94a3b8;
    margin: 0;
    max-width: 23em;
}

.aside-foot {
    margin: 0;
    font-size: .9rem;
    line-height: 1.5;
    color: #94a3b8;
    max-width: 24em;
}

/* Burbujas de chat abstractas */
.bubble {
    position: absolute;
    border-radius: 20px 20px 20px 5px;
}

.b1 {
    width: 170px;
    height: 78px;
    right: -26px;
    top: 96px;
    background: linear-gradient(135deg, #10b981, #059669);
    box-shadow: 0 14px 36px rgba(16, 185, 129, .35);
}

.b2 {
    width: 128px;
    height: 60px;
    left: 120px;
    top: 200px;
    border: 1.5px solid rgba(110, 231, 183, .5);
    border-radius: 20px 20px 5px 20px;
}

.b3 {
    width: 96px;
    height: 52px;
    right: 54px;
    top: 238px;
    background: rgba(255, 255, 255, .06);
    border: 1px solid rgba(255, 255, 255, .10);
}

.b4 {
    width: 150px;
    height: 70px;
    right: -18px;
    bottom: 200px;
    background: rgba(16, 185, 129, .14);
    border: 1px solid rgba(52, 211, 153, .4);
    border-radius: 20px 20px 5px 20px;
}

.b5 {
    width: 64px;
    height: 64px;
    left: 64px;
    bottom: 234px;
    border-radius: 50%;
    border: 1.5px dashed rgba(148, 163, 184, .35);
    display: flex;
    align-items: center;
    justify-content: center;
}

.b5 .pi {
    color: #6ee7b7;
    font-size: 1.3rem;
}

/* Efecto de brillo y malla puntada */
.va-glow {
    position: absolute;
    width: 380px;
    height: 380px;
    right: -120px;
    top: -80px;
    background: radial-gradient(circle, rgba(16, 185, 129, .22), transparent 70%);
    pointer-events: none;
}

.va-grid {
    position: absolute;
    inset: 0;
    opacity: .5;
    background-image: radial-gradient(rgba(148, 163, 184, .16) 1px, transparent 1px);
    background-size: 24px 24px;
    -webkit-mask-image: linear-gradient(135deg, #000, transparent 75%);
    mask-image: linear-gradient(135deg, #000, transparent 75%);
}

/* ════════ Panel derecho: formulario ════════ */
.login-main {
    flex: 1 1 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 48px 56px;
}

.login-form-wrap {
    width: 100%;
    max-width: 360px;
}

.form-eyebrow {
    font-size: .72rem;
    font-weight: 600;
    letter-spacing: .16em;
    text-transform: uppercase;
    color: #059669;
    margin: 0 0 10px;
}

.form-title {
    font-size: 1.7rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0 0 6px;
    letter-spacing: -.02em;
}

.form-sub {
    font-size: .92rem;
    color: #64748b;
    margin: 0 0 30px;
}

.login-form {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.field label {
    font-size: .85rem;
    font-weight: 600;
    color: #374151;
}

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

.login-error .pi {
    font-size: .9rem;
}

.form-foot {
    margin-top: 30px;
    font-size: .78rem;
    color: #94a3b8;
    text-align: center;
}

.form-foot .dot {
    margin: 0 7px;
    opacity: .6;
}

/* ════════ Responsive ════════ */
@media (max-width: 820px) {
    .login-aside {
        display: none;
    }

    .login-main {
        padding: 40px 32px;
    }

    .login-form-wrap {
        max-width: 400px;
    }
}
</style>
