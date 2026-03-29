const BASE = '/api';

function getToken() {
    return localStorage.getItem('wa_token');
}

function authHeaders() {
    const token = getToken();
    return {
        'Content-Type': 'application/json',
        ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
    };
}

async function request(path, options = {}) {
    const res = await fetch(`${BASE}${path}`, {
        headers: authHeaders(),
        ...options,
    });
    return res.json();
}

export const api = {
    health: () => fetch(`${BASE}/health`).then(r => r.json()),

    // ── Auth ─────────────────────────────────────────────────────────────────
    login: (email, password) => request('/auth/login', {
        method : 'POST',
        body   : JSON.stringify({ email, password }),
    }),

    logout: () => request('/auth/logout', { method: 'POST' }),

    me: () => request('/auth/me'),

    // ── Dashboard ─────────────────────────────────────────────────────────────
    dashboardStats: () => request('/dashboard/stats'),

    // ── Settings ─────────────────────────────────────────────────────────────
    tokenStatus: () => request('/settings/token-status'),

    updateToken: (token) => request('/settings/token', {
        method : 'POST',
        body   : JSON.stringify({ token }),
    }),

    // ── Templates ─────────────────────────────────────────────────────────────
    templates: () => request('/templates'),

    sendTest: (payload) => request('/templates/send-test', {
        method : 'POST',
        body   : JSON.stringify(payload),
    }),

    // ── Contacts ─────────────────────────────────────────────────────────────
    contacts: (params = {}) => {
        const qs = new URLSearchParams(params).toString();
        return request(`/contacts?${qs}`);
    },

    contactStats: () => request('/contacts/stats'),

    uploadContacts: (file) => {
        const formData = new FormData();
        formData.append('file', file);
        const token = getToken();
        return fetch(`${BASE}/contacts/upload`, {
            method  : 'POST',
            headers : token ? { 'Authorization': `Bearer ${token}` } : {},
            body    : formData,
        }).then(r => r.json());
    },

    optOutContact: (id) => request(`/contacts/${id}`, { method: 'DELETE' }),

    // ── Campaigns ─────────────────────────────────────────────────────────────
    campaigns: (params = {}) => {
        const qs = new URLSearchParams(params).toString();
        return request(`/campaigns?${qs}`);
    },

    createCampaign: (payload) => request('/campaigns', {
        method : 'POST',
        body   : JSON.stringify(payload),
    }),

    executeCampaign: (id) => request(`/campaigns/${id}/execute`, { method: 'POST' }),

    // ── Users ─────────────────────────────────────────────────────────────────
    users: () => request('/users'),

    createUser: (payload) => request('/users', {
        method : 'POST',
        body   : JSON.stringify(payload),
    }),

    updateUser: (id, payload) => request(`/users/${id}`, {
        method : 'PUT',
        body   : JSON.stringify(payload),
    }),

    deleteUser: (id) => request(`/users/${id}`, { method: 'DELETE' }),
};
