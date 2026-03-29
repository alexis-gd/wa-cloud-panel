import { reactive } from 'vue';
import { api } from './api.js';

const state = reactive({
    user : null,
    ready: false, // true una vez que se intentó cargar el usuario
});

export function useAuth() {
    return {
        user     : state,
        setUser  : (u) => { state.user = u; state.ready = true; },
        clearUser: ()  => { state.user = null; state.ready = true; },
        isAdmin  : ()  => state.user?.role === 'admin',
        isOperator: () => state.user?.role === 'operator',
        isAgent  : ()  => state.user?.role === 'agent',
    };
}

/**
 * Llamar una vez al arranque de la app.
 * Si hay token en localStorage, intenta recuperar el usuario.
 */
export async function initAuth() {
    const token = localStorage.getItem('wa_token');

    if (! token) {
        state.ready = true;
        return;
    }

    try {
        const res = await api.me();
        if (res.status === 'ok') {
            state.user = res.data;
        } else {
            localStorage.removeItem('wa_token');
        }
    } catch {
        localStorage.removeItem('wa_token');
    }

    state.ready = true;
}
