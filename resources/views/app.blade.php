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
        .token-input { font-family: monospace; font-size: .78rem; }
        .text-muted { font-size: .8rem; color: #888; margin-top: 4px; }
        .alert { padding: 10px 14px; border-radius: 5px; font-size: .85rem; margin-top: 10px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error   { background: #f8d7da; color: #721c24; }
        .stats-row { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
        .stat-box { background: #f8f9fa; border-radius: 6px; padding: 10px 16px; text-align: center; flex: 1; min-width: 80px; }
        .stat-box .num { font-size: 1.5rem; font-weight: 700; }
        .stat-box .lbl { font-size: .72rem; color: #888; margin-top: 2px; }
        .upload-zone { border: 2px dashed #ddd; border-radius: 6px; padding: 20px; text-align: center; cursor: pointer; margin-bottom: 10px; }
        .upload-zone:hover { border-color: #25d366; }
        .btn-sm { padding: 5px 12px; font-size: .8rem; background: #dc3545; }
        .btn-secondary { background: #6c757d; }
        input[type="file"] { padding: 4px; }
        .summary-box { background: #e8f5e9; border-radius: 6px; padding: 12px 16px; font-size: .85rem; margin-top: 10px; }
        .summary-box.has-errors { background: #fff3cd; }
        .pagination { display: flex; gap: 8px; margin-top: 10px; align-items: center; font-size: .85rem; }
        .pagination button { padding: 4px 12px; font-size: .82rem; background: #555; }
        .pagination button:disabled { background: #ccc; }
        .nav-tabs { display: flex; gap: 0; margin-bottom: 20px; border-bottom: 2px solid #ddd; }
        .nav-tab { padding: 8px 18px; cursor: pointer; font-size: .9rem; border: none; background: none; color: #666; border-bottom: 2px solid transparent; margin-bottom: -2px; }
        .nav-tab.active { color: #25d366; border-bottom-color: #25d366; font-weight: 600; }
    </style>
</head>
<body>
<div id="app">
    <h1>📱 WA Cloud Panel</h1>

    <!-- Navegación por tabs -->
    <div class="nav-tabs">
        <button class="nav-tab" :class="{active: tab==='dashboard'}" @click="tab='dashboard'">Dashboard</button>
        <button class="nav-tab" :class="{active: tab==='contacts'}"  @click="tab='contacts'; loadContacts()">Contactos</button>
        <button class="nav-tab" :class="{active: tab==='settings'}"  @click="tab='settings'">Configuración</button>
    </div>

    <!-- ===== TAB: DASHBOARD ===== -->
    <template v-if="tab === 'dashboard'">

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

    </template><!-- /tab dashboard -->

    <!-- ===== TAB: CONTACTOS ===== -->
    <template v-if="tab === 'contacts'">

    <!-- Estadísticas de contactos -->
    <div class="card">
        <h2>Resumen de contactos</h2>
        <div v-if="contactStats" class="stats-row">
            <div class="stat-box">
                <div class="num">@{{ contactStats.total }}</div>
                <div class="lbl">Total</div>
            </div>
            <div class="stat-box" style="background:#d4edda">
                <div class="num" style="color:#155724">@{{ contactStats.active }}</div>
                <div class="lbl">Activos</div>
            </div>
            <div class="stat-box" style="background:#f8d7da">
                <div class="num" style="color:#721c24">@{{ contactStats.opted_out }}</div>
                <div class="lbl">Opt-out</div>
            </div>
            <div class="stat-box" style="background:#fff3cd">
                <div class="num" style="color:#856404">@{{ contactStats.invalid }}</div>
                <div class="lbl">Inválidos</div>
            </div>
        </div>
        <div v-else style="color:#aaa;font-size:.85rem">Cargando...</div>
    </div>

    <!-- Upload Excel -->
    <div class="card">
        <h2>Cargar contactos desde Excel / CSV</h2>
        <p style="font-size:.82rem;color:#666;margin-bottom:12px">
            El archivo debe tener: <strong>Columna A</strong> = teléfono, <strong>Columna B</strong> = nombre (opcional).<br>
            Formatos aceptados: .xlsx, .xls, .csv — máx. 10 MB.<br>
            Los números se normalizan automáticamente al formato mexicano (52 + 10 dígitos).
        </p>
        <input type="file" ref="excelFile" accept=".xlsx,.xls,.csv" @change="onFileChange" />
        <div v-if="uploadFile" style="font-size:.82rem;color:#555;margin-bottom:8px">
            Archivo: @{{ uploadFile.name }} (@{{ (uploadFile.size/1024).toFixed(1) }} KB)
        </div>
        <button @click="uploadContacts" :disabled="!uploadFile || uploading">
            @{{ uploading ? 'Procesando...' : 'Subir y procesar' }}
        </button>

        <div v-if="uploadResult" :class="'summary-box' + (uploadResult.summary?.errors?.length ? ' has-errors' : '')">
            <strong>Resultado:</strong>
            @{{ uploadResult.summary?.inserted ?? 0 }} nuevos ·
            @{{ uploadResult.summary?.duplicates ?? 0 }} duplicados ·
            @{{ uploadResult.summary?.invalid ?? 0 }} inválidos
            (de @{{ uploadResult.summary?.total ?? 0 }} filas procesadas)
            <div v-if="uploadResult.error" style="color:#721c24;margin-top:4px">@{{ uploadResult.error }}</div>
            <ul v-if="uploadResult.summary?.errors?.length" style="margin-top:8px;padding-left:16px;font-size:.8rem">
                <li v-for="err in uploadResult.summary.errors" :key="err">@{{ err }}</li>
            </ul>
        </div>
    </div>

    <!-- Tabla de contactos -->
    <div class="card">
        <h2>Lista de contactos</h2>
        <div style="display:flex;gap:8px;margin-bottom:12px">
            <input v-model="contactSearch" placeholder="Buscar por teléfono o nombre..." style="margin-bottom:0;flex:1" @keyup.enter="loadContacts(1)" />
            <select v-model="contactFilter" style="width:140px;margin-bottom:0" @change="loadContacts(1)">
                <option value="">Todos</option>
                <option value="active">Activos</option>
                <option value="opted_out">Opt-out</option>
                <option value="invalid">Inválidos</option>
            </select>
            <button @click="loadContacts(1)" style="background:#555">Buscar</button>
        </div>
        <table>
            <thead>
                <tr><th>#</th><th>Teléfono</th><th>Nombre</th><th>Estado</th><th>Fuente</th><th>Fecha</th><th></th></tr>
            </thead>
            <tbody>
                <tr v-for="c in contacts" :key="c.id">
                    <td>@{{ c.id }}</td>
                    <td style="font-family:monospace">@{{ c.phone }}</td>
                    <td>@{{ c.name ?? '—' }}</td>
                    <td>
                        <span :class="'badge badge-' + (c.status === 'active' ? 'ok' : c.status === 'opted_out' ? 'failed' : 'pending')">
                            @{{ c.status }}
                        </span>
                    </td>
                    <td>@{{ c.source }}</td>
                    <td style="color:#aaa;font-size:.78rem">@{{ c.created_at?.substring(0,10) }}</td>
                    <td>
                        <button v-if="c.status === 'active'" @click="optOutContact(c)" class="btn-sm" style="padding:3px 8px;font-size:.75rem">
                            Opt-out
                        </button>
                    </td>
                </tr>
                <tr v-if="!contacts.length && !loadingContacts">
                    <td colspan="7" style="text-align:center;color:#aaa">Sin contactos</td>
                </tr>
                <tr v-if="loadingContacts">
                    <td colspan="7" style="text-align:center;color:#aaa">Cargando...</td>
                </tr>
            </tbody>
        </table>
        <div class="pagination" v-if="contactsMeta">
            <button @click="loadContacts(contactsMeta.current_page - 1)" :disabled="contactsMeta.current_page <= 1">← Ant</button>
            <span>Página @{{ contactsMeta.current_page }} de @{{ contactsMeta.last_page }}</span>
            <button @click="loadContacts(contactsMeta.current_page + 1)" :disabled="contactsMeta.current_page >= contactsMeta.last_page">Sig →</button>
            <span style="color:#aaa;margin-left:8px">@{{ contactsMeta.total }} contactos</span>
        </div>
    </div>

    </template><!-- /tab contacts -->

    <!-- ===== TAB: CONFIGURACIÓN ===== -->
    <template v-if="tab === 'settings'">

    <!-- Configuración del token -->
    <div class="card">
        <h2>🔑 Token de acceso WhatsApp</h2>
        <div v-if="tokenStatus" style="margin-bottom:12px">
            <span :class="'badge badge-' + (tokenStatus.token_valid ? 'ok' : 'error')">
                @{{ tokenStatus.token_valid ? '✓ Token válido — ' + tokenStatus.token_user : '✗ Token inválido' }}
            </span>
            <span v-if="!tokenStatus.token_valid" style="margin-left:8px;font-size:.8rem;color:#721c24">
                @{{ tokenStatus.token_error }}
            </span>
        </div>
        <label>Pegar nuevo token (desde Meta Developers → Configuración de la API)</label>
        <input
            v-model="tokenForm.token"
            class="token-input"
            type="password"
            placeholder="EAAUz..."
            @focus="$event.target.type='text'"
            @blur="$event.target.type='password'"
        />
        <p class="text-muted">El token temporal dura ~24h. Para producción usa un System User Token (no expira).</p>
        <button @click="updateToken" :disabled="tokenSaving" style="margin-top:8px">
            @{{ tokenSaving ? 'Guardando...' : 'Guardar token' }}
        </button>
        <div v-if="tokenResult" :class="'alert alert-' + (tokenResult.error ? 'error' : 'success')">
            @{{ tokenResult.error ?? tokenResult.message }}
        </div>
    </div>

    </template><!-- /tab settings -->

</div>
<script src="/assets/js/app.js"></script>
</body>
</html>
