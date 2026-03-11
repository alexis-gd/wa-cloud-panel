<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WA Cloud Panel</title>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: #f5f5f5; color: #333; }
        #app { max-width: 900px; margin: 0 auto; padding: 24px; }
        h1 { font-size: 1.4rem; margin-bottom: 20px; color: #1a1a2e; }
        h2 { font-size: 1rem; margin-bottom: 12px; color: #444; }
        .card { background: #fff; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: .75rem; font-weight: 600; }
        .badge-ok       { background: #d4edda; color: #155724; }
        .badge-error    { background: #f8d7da; color: #721c24; }
        .badge-sent     { background: #cce5ff; color: #004085; }
        .badge-delivered{ background: #d4edda; color: #155724; }
        .badge-failed   { background: #f8d7da; color: #721c24; }
        .badge-pending  { background: #fff3cd; color: #856404; }
        .badge-read     { background: #e2d9f3; color: #4a235a; }
        label { display: block; font-size: .85rem; margin-bottom: 4px; color: #555; }
        input, select { width: 100%; padding: 8px 10px; border: 1px solid #ddd; border-radius: 5px; font-size: .9rem; margin-bottom: 10px; }
        button { padding: 9px 20px; background: #25d366; color: #fff; border: none; border-radius: 5px; cursor: pointer; font-size: .9rem; }
        button:disabled { background: #aaa; cursor: not-allowed; }
        .result { margin-top: 12px; padding: 10px; background: #f8f9fa; border-radius: 5px; font-size: .82rem; white-space: pre-wrap; }
        table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; }
    </style>
</head>
<body>
<div id="app">
    <h1>📱 WA Cloud Panel</h1>

    <!-- Health -->
    <div class="card">
        <h2>Estado del sistema</h2>
        <span v-if="health" :class="'badge badge-' + (health.db === 'ok' ? 'ok' : 'error')">
            DB: @{{ health.db }}
        </span>
        <span v-else>Verificando...</span>
    </div>

    <!-- Send Test -->
    <div class="card">
        <h2>Enviar mensaje de prueba</h2>
        <label>Plantilla</label>
        <input v-model="form.template_name" placeholder="hello_world" />
        <label>Idioma</label>
        <input v-model="form.language_code" placeholder="en_US" />
        <label>Número destino (con código de país, sin +)</label>
        <input v-model="form.to" placeholder="521234567890" />
        <label>Variables del cuerpo (separadas por coma)</label>
        <input v-model="form.body_vars_raw" placeholder="Nombre, Monto" />
        <button @click="sendTest" :disabled="sending">
            @{{ sending ? 'Enviando...' : 'Enviar' }}
        </button>
        <div v-if="sendResult" class="result">@{{ JSON.stringify(sendResult, null, 2) }}</div>
    </div>

    <!-- Logs -->
    <div class="card">
        <h2>Últimos mensajes</h2>
        <button @click="loadStats" style="background:#555;margin-bottom:12px">Actualizar</button>
        <table>
            <thead>
                <tr><th>ID</th><th>Destino</th><th>Plantilla</th><th>Estado</th><th>Fecha</th></tr>
            </thead>
            <tbody>
                <tr v-for="msg in logs" :key="msg.id">
                    <td>@{{ msg.id }}</td>
                    <td>@{{ msg.to_number }}</td>
                    <td>@{{ msg.template_name }}</td>
                    <td><span :class="'badge badge-' + msg.status">@{{ msg.status }}</span></td>
                    <td>@{{ msg.created_at }}</td>
                </tr>
                <tr v-if="!logs.length"><td colspan="5" style="text-align:center;color:#aaa">Sin mensajes aún</td></tr>
            </tbody>
        </table>
    </div>
</div>
<script src="/assets/js/app.js"></script>
</body>
</html>
