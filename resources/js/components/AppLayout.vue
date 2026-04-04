<template>
    <!-- Pantalla de login: sin layout, solo RouterView -->
    <RouterView v-if="route.path === '/login'" />

    <div v-else class="app-wrapper">
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
                <RouterLink to="/campaigns" class="nav-item" :class="{ active: route.path === '/campaigns' }" @click="sidebarOpen = false">
                    <i class="pi pi-send" />
                    <span>Campañas</span>
                </RouterLink>
                <RouterLink to="/conversations" class="nav-item" :class="{ active: route.path === '/conversations' }" @click="sidebarOpen = false">
                    <i class="pi pi-comments" />
                    <span>Conversaciones</span>
                </RouterLink>

                <!-- Solo admin -->
                <template v-if="isAdmin()">
                    <RouterLink to="/templates" class="nav-item" :class="{ active: route.path === '/templates' }" @click="sidebarOpen = false">
                        <i class="pi pi-file-edit" />
                        <span>Plantillas</span>
                    </RouterLink>
                    <RouterLink to="/users" class="nav-item" :class="{ active: route.path === '/users' }" @click="sidebarOpen = false">
                        <i class="pi pi-user-edit" />
                        <span>Usuarios</span>
                    </RouterLink>
                    <RouterLink to="/settings" class="nav-item" :class="{ active: route.path === '/settings' }" @click="sidebarOpen = false">
                        <i class="pi pi-cog" />
                        <span>Configuración</span>
                    </RouterLink>
                </template>
            </nav>

            <div class="sidebar-footer">
                <div class="user-info" v-if="authState.user">
                    <span class="user-name">{{ authState.user.name }}</span>
                    <Tag :value="roleLabel" :severity="roleSeverity" class="role-tag" />
                </div>
                <Button
                    label="Cerrar sesión"
                    icon="pi pi-sign-out"
                    severity="secondary"
                    text
                    size="small"
                    class="logout-btn"
                    @click="logout"
                />
                <span class="version">v0.5.2 — Stage 3</span>
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
                    <HelpPopover
                        v-if="currentHelp"
                        :title="currentHelp.title"
                        :items="currentHelp.items"
                        :warning="currentHelp.warning"
                        :tip="currentHelp.tip"
                    />
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
import { computed, ref } from 'vue';
import { useRoute, useRouter, RouterLink, RouterView } from 'vue-router';
import Tag          from 'primevue/tag';
import Toast        from 'primevue/toast';
import Button       from 'primevue/button';
import HelpPopover  from './HelpPopover.vue';
import { api }      from '../api.js';
import { useAuth }  from '../auth.js';

const route  = useRoute();
const router = useRouter();
const { user: authState, isAdmin, clearUser } = useAuth();

const sidebarOpen = ref(false);

const pageTitles = {
    '/'               : 'Dashboard',
    '/contacts'       : 'Contactos',
    '/campaigns'      : 'Campañas',
    '/conversations'  : 'Conversaciones',
    '/templates'      : 'Plantillas',
    '/users'          : 'Usuarios',
    '/settings'       : 'Configuración',
};

const pageTitle = computed(() => pageTitles[route.path] ?? 'WA Cloud Panel');

const helpContent = {
    '/': {
        title: 'Dashboard',
        items: [
            { icon: 'pi-chart-bar',     label: 'Métricas',   text: 'Totales acumulados de enviados, entregados, leídos y fallidos.' },
            { icon: 'pi-chart-bar',     label: 'Meta mensual', text: 'Progreso del mes: mensajes enviados vs. la meta configurada. El color indica el avance (azul = ok, amarillo = por debajo, rojo = muy bajo).' },
            { icon: 'pi-chart-line',    label: 'Gráfica',    text: 'Envíos por día en los últimos 14 días. Usa ↺ para refrescar.' },
            { icon: 'pi-circle-fill',   label: 'Semáforo',   text: 'Calidad del número en Meta. Verde = ok. Amarillo = cuidado. Rojo = problema.' },
            { icon: 'pi-send',          label: 'Prueba',     text: 'Envía un mensaje individual para verificar que una plantilla funciona.' },
            { icon: 'pi-list',          label: 'Últimos',    text: 'Los 20 envíos más recientes con su estado actual.' },
        ],
        warning: 'Si el semáforo está ROJO o PAUSADO, no ejecutar campañas hasta que se revise.',
    },
    '/contacts': {
        title: 'Contactos',
        items: [
            { icon: 'pi-upload',        label: 'Importar',   text: 'Sube un Excel (.xlsx). Columna A: teléfono, Columna B: nombre (opcional).' },
            { icon: 'pi-phone',         label: 'Formato',    text: 'Teléfonos en formato mexicano con código de país: 529231311146.' },
            { icon: 'pi-check-circle',  label: 'Resultado',  text: 'Al importar verás: aceptados / duplicados / formato inválido.' },
            { icon: 'pi-ban',           label: 'Eliminar',   text: 'Borrar un contacto lo marca como opt-out permanente, no se elimina de la BD.' },
            { icon: 'pi-download',      label: 'Exportar',   text: 'Descarga la lista actual de contactos en Excel.' },
        ],
        tip: 'Los contactos con opt-out nunca reaparecen aunque se vuelvan a importar.',
    },
    '/campaigns': {
        title: 'Campañas',
        items: [
            { icon: 'pi-plus-circle',   label: 'Crear',      text: 'Dale un nombre, elige una plantilla aprobada y llena las variables si las tiene.' },
            { icon: 'pi-play-circle',   label: 'Ejecutar',   text: 'Abre la campaña y da clic en Ejecutar. Los mensajes se encolan en segundo plano.' },
            { icon: 'pi-shield',        label: 'Protección', text: 'El sistema omite automáticamente: opt-out, inválidos, snooze activo y límite diario.' },
            { icon: 'pi-clock',         label: 'Horario',    text: 'Solo envía L-V entre 9AM y 10PM hora México. Fuera de ese horario, bloquea el envío.' },
        ],
        warning: 'No ejecutar la misma campaña dos veces. Si necesitas reenviar, crea una nueva.',
    },
    '/conversations': {
        title: 'Conversaciones',
        items: [
            { icon: 'pi-comments',      label: 'Ventana 24h', text: 'Solo puedes responder texto libre si el contacto te escribió en las últimas 24h.' },
            { icon: 'pi-tag',           label: 'Tags',        text: 'Activa = puedes escribir. Cerrada = solo plantillas. Snooze = pausado. Baja = opt-out.' },
            { icon: 'pi-bolt',          label: 'Rápidas',     text: 'Los chips de respuestas rápidas cargan el texto automáticamente. Clic para usarlos.' },
            { icon: 'pi-lock',          label: 'Ventana cerrada', text: 'Cuando el campo está deshabilitado, crea una campaña con ese contacto para reabrirla.' },
        ],
        tip: 'Las respuestas rápidas las crea y elimina el administrador desde el panel derecho.',
    },
    '/templates': {
        title: 'Plantillas',
        items: [
            { icon: 'pi-sync',          label: 'Sincronizar', text: 'Trae el estado actualizado de todas las plantillas desde Meta. Úsalo si ves estados desactualizados.' },
            { icon: 'pi-check-circle',  label: 'Aprobadas',   text: 'Solo las plantillas con estado "Aprobada" aparecen al crear campañas.' },
            { icon: 'pi-clock',         label: 'Revisión',    text: 'Meta tarda entre 1 minuto y 48 horas en aprobar. Sincroniza para ver el estado actual.' },
            { icon: 'pi-image',         label: 'Header',      text: 'Las plantillas pueden tener imagen de encabezado. Se configura al crearlas.' },
        ],
        warning: 'Solo el administrador puede crear, editar o eliminar plantillas.',
    },
    '/users': {
        title: 'Usuarios',
        items: [
            { icon: 'pi-shield',        label: 'Admin',      text: 'Acceso total: plantillas, configuración, campañas, usuarios.' },
            { icon: 'pi-user',          label: 'Operador',   text: 'Puede cargar contactos, crear y ejecutar campañas, ver reportes.' },
            { icon: 'pi-comments',      label: 'Agente',     text: 'Solo puede ver y responder conversaciones entrantes.' },
        ],
        tip: 'Crea una cuenta por persona. No compartir credenciales.',
    },
    '/settings': {
        title: 'Configuración',
        items: [
            { icon: 'pi-key',           label: 'Token Meta',    text: 'El token de acceso a WhatsApp. Si los envíos fallan con error 467, el token expiró.' },
            { icon: 'pi-circle-fill',   label: 'Salud',         text: 'Muestra la calidad del número y si el circuito está pausado.' },
            { icon: 'pi-users',         label: 'Multi-agente',  text: 'Modo de asignación automática al llegar un mensaje: "Menos chats" asigna al agente con menos conversaciones; "Primer disponible" al primero activo.' },
            { icon: 'pi-clock',         label: 'Cooldown',      text: 'Días mínimos entre mensajes al mismo contacto. Default: 30 días.' },
        ],
        warning: 'El token es sensible. Solo el administrador debe actualizarlo.',
    },
};

const currentHelp = computed(() => helpContent[route.path] ?? null);

const roleLabel = computed(() => ({
    admin    : 'Admin',
    operator : 'Operador',
    agent    : 'Agente',
}[authState.user?.role] ?? ''));

const roleSeverity = computed(() => ({
    admin    : 'danger',
    operator : 'info',
    agent    : 'secondary',
}[authState.user?.role] ?? 'secondary'));

async function logout() {
    await api.logout();
    localStorage.removeItem('wa_token');
    clearUser();
    router.push('/login');
}
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

.nav-item:hover  { background: rgba(255,255,255,.07); color: #e2e8f0; }
.nav-item.active { background: rgba(16,185,129,.15); color: #10b981; font-weight: 600; }
.nav-item .pi    { font-size: 1rem; width: 18px; }

.sidebar-footer {
    padding: 14px 18px;
    border-top: 1px solid rgba(255,255,255,.08);
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.user-info   { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.user-name   { font-size: .82rem; color: #cbd5e1; font-weight: 600; flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.role-tag    { font-size: .68rem !important; }

.logout-btn { width: 100%; justify-content: flex-start; color: #64748b !important; }

.version { font-size: .68rem; color: #334155; }

/* ── Main area ─────────────────────────────────── */
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
    padding: 0 16px 0 20px;
    position: sticky;
    top: 0;
    z-index: 50;
}

.topbar-left { display: flex; align-items: center; gap: 12px; }

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

.page-title { font-size: 1rem; font-weight: 600; color: #0f172a; }

.content { padding: 24px; flex: 1; }

/* ── Responsive ────────────────────────────────── */
@media (max-width: 768px) {
    .sidebar       { transform: translateX(-100%); }
    .sidebar--open { transform: translateX(0); }
    .main          { margin-left: 0; }
    .menu-btn      { display: block; }
    .content       { padding: 16px; }
}
</style>
