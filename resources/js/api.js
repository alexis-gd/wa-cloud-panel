const BASE    = '/api';
const API_KEY = '46f747b3c452c365bf761c78b5f15c1c597f7605';

const headers = {
    'Content-Type': 'application/json',
    'X-API-Key': API_KEY,
};

async function request(path, options = {}) {
    const res  = await fetch(`${BASE}${path}`, { headers, ...options });
    return res.json();
}

export const api = {
    health:         ()           => fetch(`${BASE}/health`).then(r => r.json()),
    dashboardStats: ()           => request('/dashboard/stats'),
    tokenStatus:    ()           => request('/settings/token-status'),

    updateToken: (token) => request('/settings/token', {
        method : 'POST',
        body   : JSON.stringify({ token }),
    }),

    sendTest: (payload) => request('/templates/send-test', {
        method : 'POST',
        body   : JSON.stringify(payload),
    }),

    contacts: (params = {}) => {
        const qs = new URLSearchParams(params).toString();
        return request(`/contacts?${qs}`);
    },

    contactStats: () => request('/contacts/stats'),

    uploadContacts: (file) => {
        const formData = new FormData();
        formData.append('file', file);
        return fetch(`${BASE}/contacts/upload`, {
            method  : 'POST',
            headers : { 'X-API-Key': API_KEY },
            body    : formData,
        }).then(r => r.json());
    },

    optOutContact: (id) => request(`/contacts/${id}`, { method: 'DELETE' }),
};
