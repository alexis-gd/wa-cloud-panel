import { createApp }       from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import PrimeVue             from 'primevue/config';
import Aura                 from '@primeuix/themes/aura';
import { definePreset }     from '@primeuix/themes';
import ToastService         from 'primevue/toastservice';
import ConfirmationService  from 'primevue/confirmationservice';
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

import AppLayout      from './components/AppLayout.vue';
import LoginView      from './views/LoginView.vue';
import DashboardView  from './views/DashboardView.vue';
import ContactsView   from './views/ContactsView.vue';
import CampaignsView  from './views/CampaignsView.vue';
import UsersView      from './views/UsersView.vue';
import SettingsView   from './views/SettingsView.vue';
import { initAuth, useAuth } from './auth.js';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/login', component: LoginView, meta: { public: true } },
        { path: '/',          component: DashboardView  },
        { path: '/contacts',  component: ContactsView   },
        { path: '/campaigns', component: CampaignsView  },
        { path: '/users',     component: UsersView,  meta: { role: 'admin' } },
        { path: '/settings',  component: SettingsView, meta: { role: 'admin' } },
    ],
});

// Navigation guard — verificar auth antes de cada ruta
router.beforeEach(async (to) => {
    const { user: authState } = useAuth();

    // Esperar a que initAuth haya corrido
    if (!authState.ready) {
        await initAuth();
    }

    const loggedIn = !!authState.user;

    if (to.meta.public) {
        // Si ya está logueado y va al login, redirigir al dashboard
        if (loggedIn) return '/';
        return true;
    }

    if (!loggedIn) return '/login';

    // Verificar rol si la ruta lo requiere
    if (to.meta.role && authState.user?.role !== to.meta.role) {
        return '/';
    }

    return true;
});

createApp(AppLayout)
    .use(router)
    .use(PrimeVue, { theme: { preset: WaPreset, options: { darkModeSelector: '.app-dark' } } })
    .use(ToastService)
    .use(ConfirmationService)
    .mount('#app');
