<template>
    <div class="app-wrapper">
        <!-- Overlay mobile -->
        <div v-if="sidebarOpen" class="sidebar-overlay" @click="sidebarOpen = false" />

        <!-- Sidebar -->
        <aside class="sidebar" :class="{ 'sidebar--open': sidebarOpen }">
            <div class="sidebar-brand">
                <i class="pi pi-whatsapp" />
                <span>WA Cloud Panel</span>
            </div>

            <nav class="sidebar-nav">
                <RouterLink to="/" class="nav-item" :class="{ active: route.path === '/' }" @click="sidebarOpen = false">
                    <i class="pi pi-home" />
                    <span>Dashboard</span>
                </RouterLink>
                <RouterLink to="/contacts" class="nav-item" :class="{ active: route.path === '/contacts' }" @click="sidebarOpen = false">
                    <i class="pi pi-users" />
                    <span>Contactos</span>
                </RouterLink>
                <RouterLink to="/settings" class="nav-item" :class="{ active: route.path === '/settings' }" @click="sidebarOpen = false">
                    <i class="pi pi-cog" />
                    <span>Configuración</span>
                </RouterLink>
            </nav>

            <div class="sidebar-footer">
                <span class="version">v0.2.0 — Stage 2</span>
            </div>
        </aside>

        <!-- Main content -->
        <div class="main">
            <header class="topbar">
                <div class="topbar-left">
                    <button class="menu-btn" @click="sidebarOpen = !sidebarOpen">
                        <i class="pi pi-bars" />
                    </button>
                    <h1 class="page-title">{{ pageTitle }}</h1>
                </div>
                <div class="topbar-right">
                    <Tag v-if="tokenUser" :value="tokenUser" severity="success" icon="pi pi-check-circle" />
                    <Tag v-else value="Token inválido" severity="danger" icon="pi pi-times-circle" />
                </div>
            </header>

            <main class="content">
                <RouterView />
            </main>
        </div>
    </div>

    <Toast position="bottom-right" />
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, RouterLink, RouterView } from 'vue-router';
import Tag   from 'primevue/tag';
import Toast from 'primevue/toast';
import { api } from '../api.js';

const route       = useRoute();
const tokenUser   = ref(null);
const sidebarOpen = ref(false);

const pageTitle = computed(() => {
    const titles = { '/': 'Dashboard', '/contacts': 'Contactos', '/settings': 'Configuración' };
    return titles[route.path] ?? 'WA Cloud Panel';
});

onMounted(async () => {
    const data = await api.tokenStatus();
    if (data.token_valid) tokenUser.value = data.token_user;
});
</script>

<style scoped>
.app-wrapper {
    display: flex;
    min-height: 100vh;
    background: #f1f5f9;
}

/* ── Overlay mobile ────────────────────────────── */
.sidebar-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    z-index: 99;
}

/* ── Sidebar navy ──────────────────────────────── */
.sidebar {
    width: 220px;
    min-height: 100vh;
    background: #0f172a;
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0;
    left: 0;
    bottom: 0;
    z-index: 100;
    transition: transform .25s ease;
}

.sidebar-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 20px 18px 16px;
    font-weight: 700;
    font-size: 1rem;
    color: #10b981;
    border-bottom: 1px solid rgba(255,255,255,.08);
}

.sidebar-brand .pi { font-size: 1.4rem; }

.sidebar-nav {
    flex: 1;
    padding: 12px 8px;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 8px;
    color: #94a3b8;
    text-decoration: none;
    font-size: .9rem;
    transition: background .15s, color .15s;
}

.nav-item:hover {
    background: rgba(255,255,255,.07);
    color: #e2e8f0;
}

.nav-item.active {
    background: rgba(16,185,129,.15);
    color: #10b981;
    font-weight: 600;
}

.nav-item .pi { font-size: 1rem; width: 18px; }

.sidebar-footer {
    padding: 14px 18px;
    border-top: 1px solid rgba(255,255,255,.08);
}

.version {
    font-size: .72rem;
    color: #475569;
}

/* ── Main area (light) ─────────────────────────── */
.main {
    margin-left: 220px;
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

.topbar {
    height: 56px;
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 16px 0 20px;
    position: sticky;
    top: 0;
    z-index: 50;
}

.topbar-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.menu-btn {
    display: none;
    background: none;
    border: none;
    cursor: pointer;
    color: #0f172a;
    font-size: 1.1rem;
    padding: 4px;
    line-height: 1;
}

.page-title {
    font-size: 1rem;
    font-weight: 600;
    color: #0f172a;
}

.topbar-right { display: flex; align-items: center; gap: 10px; }

.content {
    padding: 24px;
    flex: 1;
}

/* ── Responsive ────────────────────────────────── */
@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
    }

    .sidebar--open {
        transform: translateX(0);
    }

    .main {
        margin-left: 0;
    }

    .menu-btn {
        display: block;
    }

    .content {
        padding: 16px;
    }
}
</style>
