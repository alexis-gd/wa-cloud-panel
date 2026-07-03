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

    if (res.status === 401) {
        localStorage.removeItem('wa_token');
        window.dispatchEvent(new CustomEvent('wa:session-expired'));
        return { status: 'error', code: 'UNAUTHENTICATED', message: 'Sesión expirada' };
    }

    const contentType = res.headers.get('content-type') ?? '';
    if (!contentType.includes('application/json')) {
        return { status: 'error', message: `Error del servidor (${res.status})` };
    }

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
    dashboardStats:         () => request('/dashboard/stats'),
    dashboardMonthlyHistory: () => request('/dashboard/monthly-history'),
    dashboardDailyStats: () => request('/dashboard/daily-stats'),

    // ── Settings ─────────────────────────────────────────────────────────────
    phoneHealth: () => request('/settings/phone-health'),

    tokenStatus: () => request('/settings/token-status'),

    updateToken: (token) => request('/settings/token', {
        method : 'POST',
        body   : JSON.stringify({ token }),
    }),

    getCooldown: () => request('/settings/cooldown'),

    updateCooldown: (days) => request('/settings/cooldown', {
        method : 'PUT',
        body   : JSON.stringify({ cooldown_days: days }),
    }),

    getSmsAutoBlacklist: () => request('/settings/sms-auto-blacklist'),

    updateSmsAutoBlacklist: (bounces) => request('/settings/sms-auto-blacklist', {
        method : 'PUT',
        body   : JSON.stringify({ sms_auto_blacklist_bounces: bounces }),
    }),

    demoReset: () => request('/settings/demo-reset', { method: 'POST' }),

    // ── Templates ─────────────────────────────────────────────────────────────
    templates: () => request('/templates'),

    createTemplate: (payload) => request('/templates', {
        method : 'POST',
        body   : JSON.stringify(payload),
    }),

    updateTemplate: (id, payload) => request(`/templates/${id}`, {
        method : 'PUT',
        body   : JSON.stringify(payload),
    }),

    deleteTemplate: (id) => request(`/templates/${id}`, { method: 'DELETE' }),

    syncTemplates: () => request('/templates/sync', { method: 'POST' }),

    sendTest: (payload) => request('/templates/send-test', {
        method : 'POST',
        body   : JSON.stringify(payload),
    }),

    sendSmsTest: (payload) => request('/sms/send-test', {
        method : 'POST',
        body   : JSON.stringify(payload),
    }),

    smsInbound: (params = {}) => {
        const qs = new URLSearchParams(params).toString();
        return request(`/sms/inbound?${qs}`);
    },

    // ── Contacts ─────────────────────────────────────────────────────────────
    contacts: (params = {}) => {
        const qs = new URLSearchParams(params).toString();
        return request(`/contacts?${qs}`);
    },

    contactStats: () => request('/contacts/stats'),

    checkContact: (phone) => request(`/contacts/check?phone=${encodeURIComponent(phone)}`),

    createContact: (payload) => request('/contacts', {
        method : 'POST',
        body   : JSON.stringify(payload),
    }),

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

    optOutContact: (id) => request(`/contacts/${id}/opt-out`, { method: 'POST' }),

    deleteContact: (id) => request(`/contacts/${id}`, { method: 'DELETE' }),

    updateContact: (id, payload) => request(`/contacts/${id}`, {
        method : 'PUT',
        body   : JSON.stringify(payload),
    }),

    // ── Tags ─────────────────────────────────────────────────────────────────
    tags: () => request('/tags'),

    createTag: (name) => request('/tags', {
        method : 'POST',
        body   : JSON.stringify({ name }),
    }),

    deleteTag: (id) => request(`/tags/${id}`, { method: 'DELETE' }),

    syncContactTags: (contactId, tagIds) => request(`/contacts/${contactId}/tags`, {
        method : 'PUT',
        body   : JSON.stringify({ tag_ids: tagIds }),
    }),

    bulkAttachTag: (contactIds, tagId) => request('/contacts/tags/bulk-attach', {
        method : 'POST',
        body   : JSON.stringify({ contact_ids: contactIds, tag_id: tagId }),
    }),

    bulkDetachTag: (contactIds, tagId) => request('/contacts/tags/bulk-detach', {
        method : 'POST',
        body   : JSON.stringify({ contact_ids: contactIds, tag_id: tagId }),
    }),

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

    campaignLogs: (id, params = {}) => {
        const qs = new URLSearchParams(params).toString();
        return request(`/campaigns/${id}/logs?${qs}`);
    },

    pauseCampaign: (id) => request(`/campaigns/${id}/pause`, { method: 'POST' }),

    retryPending: (id) => request(`/campaigns/${id}/retry-pending`, { method: 'POST' }),

    deleteCampaign: (id) => request(`/campaigns/${id}`, { method: 'DELETE' }),

    // ── Conversations ─────────────────────────────────────────────────────────
    conversations: () => request('/conversations'),

    conversation: (contactId) => request(`/conversations/${contactId}`),

    sendMessage: (contactId, body) => request(`/conversations/${contactId}/messages`, {
        method : 'POST',
        body   : JSON.stringify({ body }),
    }),

    quickReplies: () => request('/quick-replies'),

    createQuickReply: (payload) => request('/quick-replies', {
        method : 'POST',
        body   : JSON.stringify(payload),
    }),

    deleteQuickReply: (id) => request(`/quick-replies/${id}`, { method: 'DELETE' }),

    assignConversation: (contactId, userId) => request(`/conversations/${contactId}/assign`, {
        method : 'POST',
        body   : JSON.stringify({ user_id: userId }),
    }),

    claimConversation: (contactId) => request(`/conversations/${contactId}/claim`, { method: 'POST' }),

    // ── Dashboard messages ────────────────────────────────────────────────────
    dashboardMessages: (params = {}) => {
        const qs = new URLSearchParams(params).toString();
        return request(`/dashboard/messages?${qs}`);
    },

    getAssignmentMode: () => request('/settings/assignment-mode'),

    updateAssignmentMode: (mode) => request('/settings/assignment-mode', {
        method : 'PUT',
        body   : JSON.stringify({ assignment_mode: mode }),
    }),

    getMonthlyGoal: () => request('/settings/monthly-goal'),

    updateMonthlyGoal: (goal) => request('/settings/monthly-goal', {
        method : 'PUT',
        body   : JSON.stringify({ monthly_goal: goal }),
    }),

    // ── Feature flags ─────────────────────────────────────────────────────────
    getFeatures: () => request('/settings/features'),

    updateFeatures: (payload) => request('/settings/features', {
        method : 'PUT',
        body   : JSON.stringify(payload),
    }),

    // ── Notifications ─────────────────────────────────────────────────────────
    notifications: () => request('/notifications'),

    markNotificationsRead: () => request('/notifications/read-all', { method: 'POST' }),

    deleteNotification: (id) => request(`/notifications/${id}`, { method: 'DELETE' }),

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
