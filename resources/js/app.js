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
import DashboardView  from './views/DashboardView.vue';
import ContactsView   from './views/ContactsView.vue';
import SettingsView   from './views/SettingsView.vue';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/',         component: DashboardView },
        { path: '/contacts', component: ContactsView  },
        { path: '/settings', component: SettingsView  },
    ],
});

createApp(AppLayout)
    .use(router)
    .use(PrimeVue, { theme: { preset: WaPreset, options: { darkModeSelector: '.app-dark' } } })
    .use(ToastService)
    .use(ConfirmationService)
    .mount('#app');
