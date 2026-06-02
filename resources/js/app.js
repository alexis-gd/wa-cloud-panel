import { createApp }       from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import PrimeVue             from 'primevue/config';
import Aura                 from '@primeuix/themes/aura';
import { definePreset }     from '@primeuix/themes';
import ToastService         from 'primevue/toastservice';
import ConfirmationService  from 'primevue/confirmationservice';
import Tooltip              from 'primevue/tooltip';
import 'primeicons/primeicons.css';

const WaPreset = definePreset(Aura, {
    semantic: {
        primary: {
            50:  '#ecfdf5',
            100: '#d1fae5',
            200: '#a7f3d0',
            300: '#6ee7b7',
            400: '#34d399',
            500: '#10b981',
            600: '#059669',
            700: '#047857',
            800: '#065f46',
            900: '#064e3b',
            950: '#022c22',
        },
    },
});

import AppLayout           from './components/AppLayout.vue';
import LoginView           from './views/LoginView.vue';
import DashboardView       from './views/DashboardView.vue';
import ContactsView        from './views/ContactsView.vue';
import CampaignsView       from './views/CampaignsView.vue';
import ConversationsView   from './views/ConversationsView.vue';
import TemplatesView       from './views/TemplatesView.vue';
import UsersView           from './views/UsersView.vue';
import SettingsView        from './views/SettingsView.vue';
import { initAuth, useAuth } from './auth.js';
import { initFeatures, useFeatures } from './features.js';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/login', component: LoginView, meta: { public: true } },
        { path: '/',          component: DashboardView  },
        { path: '/contacts',       component: ContactsView      },
        { path: '/campaigns',      component: CampaignsView     },
        { path: '/conversations',  component: ConversationsView  },
        { path: '/templates',     component: TemplatesView, meta: { role: 'admin' } },
        { path: '/users',     component: UsersView,  meta: { role: 'admin' } },
        { path: '/settings',  component: SettingsView, meta: { role: 'superadmin' } },
    ],
});

// Marcar app como lista DESPUÉS de que la navegación completó —
// evita que AppLayout re-renderice con appReady=true mientras route.path
// todavía apunta a la ruta protegida (antes de que el redirect a /login ocurra).
router.afterEach(() => {
    const { user: authState, setAppReady } = useAuth();
    if (!authState.appReady) setAppReady();
});

// Navigation guard — verificar auth y feature flags antes de cada ruta
router.beforeEach(async (to) => {
    const { user: authState } = useAuth();
    const { isEnabled } = useFeatures();

    // Esperar a que initAuth + initFeatures hayan corrido (solo una vez)
    if (!authState.ready) {
        await initAuth();
        if (authState.user) await initFeatures();
    }

    const loggedIn = !!authState.user;

    if (to.meta.public) {
        // Si ya está logueado y va al login, redirigir al dashboard
        if (loggedIn) return '/';
        return true;
    }

    if (!loggedIn) return '/login';

    // Verificar rol — superadmin pasa cualquier check
    if (to.meta.role) {
        const userRole = authState.user?.role;
        if (userRole !== to.meta.role && userRole !== 'superadmin') return '/';
    }

    // Verificar feature flags por ruta — superadmin bypasa, dashboard siempre accesible
    if (authState.user?.role !== 'superadmin') {
        const featureForRoute = {
            '/contacts':      'feature_contacts',
            '/campaigns':     'feature_campaigns',
            '/templates':     'feature_templates',
            '/users':         'feature_users',
            '/conversations': 'feature_conversations',
        };
        const requiredFlag = featureForRoute[to.path];
        if (requiredFlag && !isEnabled(requiredFlag)) return '/';
    }

    return true;
});

createApp(AppLayout)
    .use(router)
    .use(PrimeVue, { theme: { preset: WaPreset, options: { darkModeSelector: '.app-dark' } } })
    .use(ToastService)
    .use(ConfirmationService)
    .directive('tooltip', Tooltip)
    .mount('#app');
