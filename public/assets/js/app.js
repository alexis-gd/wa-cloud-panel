const API_KEY = '46f747b3c452c365bf761c78b5f15c1c597f7605';
const BASE    = '/api';

const headers = {
    'Content-Type': 'application/json',
    'X-API-Key': API_KEY,
};

Vue.createApp({
    data() {
        return {
            health:     null,
            logs:       [],
            sending:    false,
            sendResult: null,
            form: {
                template_name: 'hello_world',
                language_code: 'en_US',
                to:            '',
                body_vars_raw: '',
            },
        };
    },

    async mounted() {
        await this.checkHealth();
        await this.loadStats();
    },

    methods: {
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
    },
}).mount('#app');
