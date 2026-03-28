const API_KEY = '46f747b3c452c365bf761c78b5f15c1c597f7605';
const BASE    = '/api';

const headers = {
    'Content-Type': 'application/json',
    'X-API-Key': API_KEY,
};

Vue.createApp({
    data() {
        return {
            tab: 'dashboard',

            // --- Dashboard ---
            health:      null,
            logs:        [],
            sending:     false,
            sendResult:  null,
            form: {
                template_name: 'hello_world',
                language_code: 'en_US',
                to:            '',
                body_vars_raw: '',
            },

            // --- Configuración ---
            tokenStatus: null,
            tokenSaving: false,
            tokenResult: null,
            tokenForm: { token: '' },

            // --- Contactos ---
            contacts:       [],
            contactsMeta:   null,
            contactStats:   null,
            contactSearch:  '',
            contactFilter:  '',
            loadingContacts: false,
            uploadFile:     null,
            uploading:      false,
            uploadResult:   null,
        };
    },

    async mounted() {
        await Promise.all([
            this.checkHealth(),
            this.loadStats(),
            this.checkTokenStatus(),
        ]);
    },

    methods: {
        // ── Dashboard ────────────────────────────────────────
        async checkHealth() {
            const res = await fetch(`${BASE}/health`);
            this.health = await res.json();
        },

        async loadStats() {
            const res  = await fetch(`${BASE}/dashboard/stats`, { headers });
            const data = await res.json();
            this.logs  = data.recent_messages ?? [];
        },

        async sendTest() {
            this.sending    = true;
            this.sendResult = null;

            const bodyVars = this.form.body_vars_raw
                ? this.form.body_vars_raw.split(',').map(v => v.trim()).filter(Boolean)
                : [];

            const res = await fetch(`${BASE}/templates/send-test`, {
                method:  'POST',
                headers,
                body: JSON.stringify({
                    template_name: this.form.template_name,
                    language_code: this.form.language_code,
                    to:            this.form.to,
                    body_vars:     bodyVars,
                }),
            });

            this.sendResult = await res.json();
            this.sending    = false;
            await this.loadStats();
        },

        // ── Configuración ────────────────────────────────────
        async checkTokenStatus() {
            const res = await fetch(`${BASE}/settings/token-status`, { headers });
            this.tokenStatus = await res.json();
        },

        async updateToken() {
            this.tokenSaving = true;
            this.tokenResult = null;

            const res = await fetch(`${BASE}/settings/token`, {
                method:  'POST',
                headers,
                body: JSON.stringify({ token: this.tokenForm.token }),
            });

            this.tokenResult = await res.json();
            this.tokenSaving = false;
            this.tokenForm.token = '';
            await this.checkTokenStatus();
        },

        // ── Contactos ────────────────────────────────────────
        async loadContacts(page = 1) {
            this.loadingContacts = true;

            const params = new URLSearchParams({ page });
            if (this.contactFilter) params.set('status', this.contactFilter);
            if (this.contactSearch)  params.set('q',      this.contactSearch);

            const res  = await fetch(`${BASE}/contacts?${params}`, { headers });
            const data = await res.json();

            this.contacts     = data.data ?? [];
            this.contactsMeta = data;
            this.loadingContacts = false;

            // Refrescar estadísticas al cargar
            this.loadContactStats();
        },

        async loadContactStats() {
            const res = await fetch(`${BASE}/contacts/stats`, { headers });
            this.contactStats = await res.json();
        },

        onFileChange(e) {
            this.uploadFile   = e.target.files[0] ?? null;
            this.uploadResult = null;
        },

        async uploadContacts() {
            if (!this.uploadFile) return;

            this.uploading    = true;
            this.uploadResult = null;

            const formData = new FormData();
            formData.append('file', this.uploadFile);

            const res = await fetch(`${BASE}/contacts/upload`, {
                method:  'POST',
                headers: { 'X-API-Key': API_KEY }, // sin Content-Type para multipart/form-data
                body:    formData,
            });

            this.uploadResult = await res.json();
            this.uploading    = false;
            this.$refs.excelFile.value = '';
            this.uploadFile   = null;

            // Recargar lista y estadísticas
            if (this.uploadResult.success) {
                await this.loadContacts(1);
            }
        },

        async optOutContact(contact) {
            if (!confirm(`¿Marcar ${contact.phone} como opt-out? Esta acción no se puede deshacer.`)) {
                return;
            }

            await fetch(`${BASE}/contacts/${contact.id}`, {
                method:  'DELETE',
                headers,
            });

            await this.loadContacts(this.contactsMeta?.current_page ?? 1);
        },
    },
}).mount('#app');
