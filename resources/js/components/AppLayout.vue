<template>
    <!-- Loading screen mientras auth + features inicializan -->
    <div v-if="!authState.appReady" class="app-loading">
        <i class="pi pi-spin pi-spinner app-loading__icon" />
    </div>

    <!-- Pantalla de login: sin layout, solo RouterView -->
    <RouterView v-else-if="route.path === '/login'" />

    <div v-else class="app-wrapper">
        <!-- Overlay mobile -->
        <div v-if="sidebarOpen" class="sidebar-overlay" @click="sidebarOpen = false" />

        <!-- Sidebar -->
        <aside class="sidebar" :class="{ 'sidebar--open': sidebarOpen }">
            <div class="sidebar-brand">
                <i class="pi pi-whatsapp" />
                <span>Prestamaz Panel</span>
            </div>

            <nav class="sidebar-nav">
                <!-- Dashboard: nav oculto si flag off, ruta siempre accesible (fallback) -->
                <RouterLink v-if="isEnabled('feature_dashboard')" to="/" class="nav-item" :class="{ active: route.path === '/' }" @click="sidebarOpen = false">
                    <i class="pi pi-home" />
                    <span>Dashboard</span>
                </RouterLink>
                <RouterLink v-if="isEnabled('feature_contacts')" to="/contacts" class="nav-item" :class="{ active: route.path === '/contacts' }" @click="sidebarOpen = false">
                    <i class="pi pi-users" />
                    <span>Contactos</span>
                </RouterLink>
                <RouterLink v-if="isEnabled('feature_campaigns')" to="/campaigns" class="nav-item" :class="{ active: route.path === '/campaigns' }" @click="sidebarOpen = false">
                    <i class="pi pi-send" />
                    <span>Campañas</span>
                </RouterLink>
                <RouterLink v-if="isEnabled('feature_campaigns')" to="/sms-replies" class="nav-item" :class="{ active: route.path === '/sms-replies' }" @click="sidebarOpen = false">
                    <i class="pi pi-inbox" />
                    <span>Respuestas SMS</span>
                </RouterLink>
                <RouterLink v-if="isEnabled('feature_conversations')" to="/conversations" class="nav-item" :class="{ active: route.path === '/conversations' }" @click="sidebarOpen = false">
                    <i class="pi pi-comments" />
                    <span>Conversaciones</span>
                </RouterLink>

                <!-- Solo admin -->
                <RouterLink v-if="isAdmin() && isEnabled('feature_templates')" to="/templates" class="nav-item" :class="{ active: route.path === '/templates' }" @click="sidebarOpen = false">
                    <i class="pi pi-file-edit" />
                    <span>Plantillas</span>
                </RouterLink>
                <RouterLink v-if="isAdmin() && isEnabled('feature_users')" to="/users" class="nav-item" :class="{ active: route.path === '/users' }" @click="sidebarOpen = false">
                    <i class="pi pi-user-edit" />
                    <span>Usuarios</span>
                </RouterLink>

                <!-- Solo superadmin -->
                <RouterLink v-if="isSuperAdmin()" to="/settings" class="nav-item" :class="{ active: route.path === '/settings' }" @click="sidebarOpen = false">
                    <i class="pi pi-cog" />
                    <span>Configuración</span>
                </RouterLink>
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
                <span class="version">v0.11.0 - Stage 3</span>
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

                <div class="topbar-right">
                    <button class="notif-btn" @click="openNotifications" title="Notificaciones">
                        <i class="pi pi-bell" />
                        <span v-if="notifUnread > 0" class="notif-badge">{{ notifUnread > 9 ? '9+' : notifUnread }}</span>
                    </button>
                    <Popover ref="notifPanel" class="notif-pop">
                        <div class="notif-content">
                            <p class="notif-header">
                                <i class="pi pi-bell" />
                                Notificaciones
                                <span v-if="notifUnread > 0" class="notif-header-badge">{{ notifUnread }} sin leer</span>
                            </p>
                            <div v-if="notifList.length === 0" class="notif-empty">
                                Sin notificaciones recientes.
                            </div>
                            <ul v-else class="notif-list">
                                <li
                                    v-for="n in notifList"
                                    :key="n.id"
                                    class="notif-item"
                                    :class="{ 'notif-item--unread': !n.read }"
                                >
                                    <i class="pi pi-exclamation-circle notif-item-icon" />
                                    <div class="notif-item-body">
                                        <span class="notif-item-title">{{ n.title }}</span>
                                        <span class="notif-item-text">{{ n.body }}</span>
                                        <span class="notif-item-time">{{ n.created_at }}</span>
                                    </div>
                                    <button class="notif-delete-btn" @click.stop="deleteNotification(n.id)" title="Eliminar">
                                        <i class="pi pi-times" />
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </Popover>
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
import { computed, ref, onMounted, onUnmounted } from 'vue';
import { useRoute, useRouter, RouterLink, RouterView } from 'vue-router';
import { useToast } from 'primevue/usetoast';
import Tag          from 'primevue/tag';
import Toast        from 'primevue/toast';
import Button       from 'primevue/button';
import Popover      from 'primevue/popover';
import HelpPopover  from './HelpPopover.vue';
import { api }         from '../api.js';
import { useAuth }     from '../auth.js';
import { useFeatures } from '../features.js';

const route  = useRoute();
const router = useRouter();
const toast  = useToast();
const { user: authState, isAdmin, isSuperAdmin, clearUser, isInitialLoad } = useAuth();
const { isEnabled } = useFeatures();

function handleSessionExpired() {
    if (!authState.user) return; // ya fue manejado, no repetir
    clearUser();
    // Durante carga inicial: el guard ya redirige a /login — sin toast ni push duplicado
    if (isInitialLoad()) return;
    toast.add({
        severity : 'warn',
        summary  : 'Sesión expirada',
        detail   : 'Tu sesión ha expirado. Por favor inicia sesión nuevamente.',
        life     : 5000,
    });
    router.push('/login');
}

async function handleVisibilityChange() {
    if (document.visibilityState !== 'visible' || !authState.user) return;
    const res = await api.me();
    // Cubre 401 (token expirado) y 500/otros (token ausente o servidor)
    if (res.status !== 'ok') handleSessionExpired();
}

// ── Notifications ────────────────────────────────────────────────────────────
const notifPanel   = ref(null);
const notifUnread  = ref(0);
const notifList    = ref([]);
let   notifTimer   = null;
let   notifInitial = true; // no disparar toast en la carga inicial

async function fetchNotifications() {
    if (!authState.user) return;
    const res = await api.notifications();
    if (res.status === 'ok') {
        const prevUnread = notifUnread.value;
        const newUnread  = res.data.unread_count;

        notifUnread.value = newUnread;
        notifList.value   = res.data.notifications;

        // Toast solo en polls posteriores a la carga inicial
        if (!notifInitial && newUnread > prevUnread) {
            const newest = res.data.notifications.find(n => !n.read);
            if (newest) {
                toast.add({
                    severity : 'warn',
                    summary  : newest.title,
                    detail   : newest.body,
                    life     : 7000,
                });
            }
        }
        notifInitial = false;
    }
}

async function openNotifications(event) {
    notifPanel.value.toggle(event);
    if (notifUnread.value > 0) {
        await api.markNotificationsRead();
        notifUnread.value = 0;
        notifList.value   = notifList.value.map(n => ({ ...n, read: true }));
    }
}

async function deleteNotification(id) {
    await api.deleteNotification(id);
    notifList.value = notifList.value.filter(n => n.id !== id);
}

onMounted(() => {
    window.addEventListener('wa:session-expired', handleSessionExpired);
    document.addEventListener('visibilitychange', handleVisibilityChange);
    fetchNotifications();
    notifTimer = setInterval(fetchNotifications, 30_000);
});

onUnmounted(() => {
    window.removeEventListener('wa:session-expired', handleSessionExpired);
    document.removeEventListener('visibilitychange', handleVisibilityChange);
    clearInterval(notifTimer);
});

const sidebarOpen = ref(false);

const pageTitles = {
    '/'               : 'Dashboard',
    '/contacts'       : 'Contactos',
    '/campaigns'      : 'Campañas',
    '/sms-replies'    : 'Respuestas SMS',
    '/conversations'  : 'Conversaciones',
    '/templates'      : 'Plantillas',
    '/users'          : 'Usuarios',
    '/settings'       : 'Configuración',
};

const pageTitle = computed(() => pageTitles[route.path] ?? 'Prestamaz Panel');

const helpContent = {
    '/': {
        title: 'Dashboard',
        items: [
            { icon: 'pi-circle-fill',   label: 'Semáforo',    text: 'Calidad del número en Meta. Verde = ok. Amarillo = cuidado. Rojo = problema. Si está PAUSADO, el sistema activó el circuit breaker: detectó un error de calidad o spam y pausó los envíos automáticamente 60 minutos para proteger la cuenta. Se reanuda solo.' },
            { icon: 'pi-chart-bar',     label: 'Meta mensual', text: 'Progreso del mes: mensajes enviados vs. la capacidad real del sistema (días hábiles × límite diario). El color indica el avance.' },
            { icon: 'pi-send',          label: 'Mensajes',    text: 'Totales acumulados históricos de enviados (en tránsito), entregados, leídos y fallidos.' },
            { icon: 'pi-users',         label: 'Contactos',   text: 'Estado actual de la base: total, activos (pueden recibir mensajes), opt-out e inválidos.' },
            { icon: 'pi-chart-line',    label: 'Gráfica',     text: 'Envíos día a día del mes actual. Usa ↺ para refrescar.' },
            { icon: 'pi-history',       label: 'Histórico',   text: 'Enviados vs. capacidad de los últimos 6 meses. Útil para ver tendencia de crecimiento.' },
            { icon: 'pi-list',          label: 'Últimos',     text: 'Los 10 mensajes más recientes con su estado actual. Filtra por estado con el selector.' },
        ],
        warning: 'Si el semáforo está ROJO o PAUSADO, no ejecutar campañas hasta que se revise.',
    },
    '/contacts': {
        title: 'Contactos',
        items: [
            { icon: 'pi-upload',        label: 'Importar',   text: 'Sube un Excel (.xlsx). Columna A: teléfono, Columna B: nombre (opcional).' },
            { icon: 'pi-plus',          label: 'Agregar uno', text: 'Botón "Agregar contacto" para alta manual. Al teclear el número te avisa si ya existe, está bloqueado o en cooldown.' },
            { icon: 'pi-phone',         label: 'Formato',    text: 'Teléfonos en formato mexicano con código de país: 529231311146.' },
            { icon: 'pi-check-circle',  label: 'Resultado',  text: 'Al importar verás: aceptados / duplicados / formato inválido.' },
            { icon: 'pi-ban',           label: 'Opt-out',    text: 'El botón Opt-out marca al contacto como baja permanente (cumplimiento). Nunca más se le envía.' },
            { icon: 'pi-trash',         label: 'Eliminar',   text: 'El bote de basura (solo admin) quita el contacto de listas y campañas — para limpiar pruebas/basura. Es recuperable y no afecta las stats de opt-out.' },
            { icon: 'pi-send',          label: 'Entregabilidad', text: 'Columna que indica si al contacto le llega ahora: Disponible, En snooze, En cooldown, Enviado hoy o No recibe (bloqueado). Distinta del Estado.' },
            { icon: 'pi-mobile',        label: 'Baja SMS',   text: 'Chip rojo bajo el Estado cuando el contacto NO recibe SMS (pidió baja por SMS, bloqueado o número inválido). Es independiente del Estado de WhatsApp: puede estar Activo para WhatsApp y con Baja SMS. Filtra con "Solo bajas SMS".' },
            { icon: 'pi-tag',           label: 'Tags masivos', text: 'Marca varios contactos con las casillas y usa la barra superior para asignar un tag a todos a la vez.' },
            { icon: 'pi-download',      label: 'Exportar',   text: 'Descarga la lista actual de contactos en Excel.' },
        ],
        tip: 'Los contactos con opt-out nunca reaparecen aunque se vuelvan a importar.',
    },
    '/campaigns': {
        title: 'Campañas',
        items: [
            { icon: 'pi-plus-circle',   label: 'Crear',      text: 'Dale un nombre y elige el canal. WhatsApp usa una plantilla aprobada; SMS usa mensaje libre (incluye "STOP para baja").' },
            { icon: 'pi-play-circle',   label: 'Ejecutar',   text: 'Abre la campaña y da clic en Ejecutar. Los mensajes se encolan en segundo plano.' },
            { icon: 'pi-shield',        label: 'Protección', text: 'Omite automáticamente: opt-out (una baja en WhatsApp también frena SMS), inválidos y snooze. El dedup diario y el cooldown son por canal, separados.' },
            { icon: 'pi-clock',         label: 'Horario',    text: 'WhatsApp solo L-V 9AM–10PM (fuera de eso bloquea). SMS no tiene horario forzado: tú decides (aviso si es de madrugada).' },
        ],
        warning: 'No ejecutar la misma campaña dos veces. Si necesitas reenviar, crea una nueva.',
    },
    '/sms-replies': {
        title: 'Respuestas SMS',
        items: [
            { icon: 'pi-inbox',   label: 'Qué es',   text: 'Lista de los SMS que tus contactos respondieron. Es de solo lectura: no se contesta desde aquí (a diferencia de Conversaciones de WhatsApp).' },
            { icon: 'pi-ban',     label: 'Baja automática', text: 'Si alguien responde STOP o BAJA, el sistema lo da de baja de SMS solo y lo marca con la etiqueta roja "Baja automática".' },
            { icon: 'pi-search',  label: 'Buscar',   text: 'Filtra por número o por texto del mensaje. El botón "Solo bajas" deja ver únicamente las bajas.' },
            { icon: 'pi-refresh', label: 'Actualizar', text: 'Usa ↻ para traer las respuestas más recientes.' },
        ],
        tip: 'Aunque el número no esté en tus contactos, su respuesta aparece igual aquí.',
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
            { icon: 'pi-send',          label: 'Enviar prueba', text: 'Selecciona una plantilla aprobada y usa el botón "Enviar prueba" para mandar un mensaje de prueba a un contacto activo.' },
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
    superadmin: 'Super Admin',
    admin    : 'Admin',
    operator : 'Operador',
    agent    : 'Agente',
}[authState.user?.role] ?? ''));

const roleSeverity = computed(() => ({
    superadmin: 'warn',
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
    justify-content: space-between;
    padding: 0 16px 0 20px;
    position: sticky;
    top: 0;
    z-index: 50;
}

.topbar-left  { display: flex; align-items: center; gap: 12px; }
.topbar-right { display: flex; align-items: center; }

/* ── Notification bell ─────────────────────────── */
.notif-btn {
    position: relative;
    background: none;
    border: none;
    cursor: pointer;
    color: #64748b;
    font-size: 1.1rem;
    padding: 6px 8px;
    border-radius: 8px;
    line-height: 1;
    transition: color .15s, background .15s;
}
.notif-btn:hover { color: #0f172a; background: #f1f5f9; }

.notif-badge {
    position: absolute;
    top: 2px;
    right: 2px;
    background: #ef4444;
    color: #fff;
    font-size: .6rem;
    font-weight: 700;
    min-width: 16px;
    height: 16px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 3px;
    line-height: 1;
}

/* ── Notification panel (Popover interior) ─────── */
.notif-content { width: 340px; padding: 4px 2px; }

.notif-header {
    font-size: .85rem;
    font-weight: 700;
    color: var(--p-text-color);
    margin: 0 0 10px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.notif-header .pi { color: var(--p-primary-500); }

.notif-header-badge {
    margin-left: auto;
    font-size: .7rem;
    font-weight: 600;
    color: #ef4444;
    background: #fee2e2;
    border-radius: 10px;
    padding: 2px 7px;
}

.notif-empty {
    font-size: .82rem;
    color: var(--p-text-muted-color);
    padding: 8px 0;
    text-align: center;
}

.notif-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 2px; }

.notif-item {
    display: flex;
    gap: 10px;
    padding: 8px 6px;
    border-radius: 6px;
    transition: background .12s;
}
.notif-item:hover { background: var(--p-surface-100); }
.notif-item--unread { background: var(--p-primary-50); }

.notif-item-icon {
    font-size: .9rem;
    color: #ef4444;
    margin-top: 2px;
    flex-shrink: 0;
}

.notif-item-body {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.notif-item-title {
    font-size: .8rem;
    font-weight: 700;
    color: var(--p-text-color);
}

.notif-item-text {
    font-size: .78rem;
    color: var(--p-text-color);
    line-height: 1.4;
    white-space: normal;
}

.notif-item-time {
    font-size: .7rem;
    color: var(--p-text-muted-color);
    margin-top: 2px;
}

.notif-delete-btn {
    margin-left: auto;
    align-self: flex-start;
    flex-shrink: 0;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--p-text-muted-color);
    font-size: .75rem;
    padding: 3px 5px;
    border-radius: 4px;
    line-height: 1;
    opacity: 0;
    transition: opacity .15s, color .15s, background .15s;
}
.notif-item:hover .notif-delete-btn { opacity: 1; }
.notif-delete-btn:hover { color: #ef4444; background: #fee2e2; }

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

/* ── Loading screen inicial ────────────────────── */
.app-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    background: #0f172a;
}

.app-loading__icon {
    font-size: 2rem;
    color: #10b981;
}

/* ── Responsive ────────────────────────────────── */
@media (max-width: 768px) {
    .sidebar       { transform: translateX(-100%); }
    .sidebar--open { transform: translateX(0); }
    .main          { margin-left: 0; }
    .menu-btn      { display: block; }
    .content       { padding: 16px; }
}
</style>
