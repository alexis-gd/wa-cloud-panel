import { reactive } from 'vue';
import { api } from './api.js';
import { useAuth } from './auth.js';

const state = reactive({ flags: {}, ready: false });

export function useFeatures() {
    return {
        // superadmin bypasa todos los feature flags — ve el sistema completo siempre
        isEnabled: (key) => {
            const { isSuperAdmin } = useAuth();
            if (isSuperAdmin()) return true;
            return state.ready && state.flags[key] !== false;
        },
        flags: state.flags,
    };
}

export async function initFeatures() {
    try {
        const res = await api.getFeatures();
        if (res.status === 'ok') {
            Object.assign(state.flags, res.data);
        }
    } finally {
        state.ready = true;
    }
}
