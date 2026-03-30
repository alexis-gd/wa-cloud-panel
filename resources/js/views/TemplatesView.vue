<template>
  <div class="templates-page">

    <!-- Header -->
    <div class="page-header">
      <div>
        <h2 class="page-title">Plantillas de mensaje</h2>
        <p class="page-subtitle">Solo se usan plantillas aprobadas por Meta para los envíos.</p>
      </div>
      <div class="header-actions">
        <Button label="Sincronizar con Meta" icon="pi pi-refresh" severity="secondary" raised :loading="syncing" @click="syncTemplates" />
      </div>
    </div>

    <!-- Alertas de calidad -->
    <div v-for="t in alertTemplates" :key="`alert-${t.id}`" class="alert-banner"
      :class="t.status === 'rejected' || t.quality_score === 'RED' ? 'alert-danger' : 'alert-warn'">
      <i class="pi" :class="t.status === 'rejected' ? 'pi-times-circle' : 'pi-exclamation-triangle'"></i>
      <span>
        <strong>{{ t.name }}</strong>:
        <span v-if="t.status === 'rejected'">Rechazada por Meta — {{ t.rejection_reason || 'sin motivo indicado' }}</span>
        <span v-else-if="t.status === 'paused'">Pausada por Meta</span>
        <span v-else>Calidad {{ t.quality_score }} — puede afectar entregas</span>
      </span>
    </div>

    <!-- Contenido principal -->
    <div class="main-layout">

      <!-- Tabla izquierda -->
      <div class="table-panel">
        <div v-if="loading" class="empty-state">Cargando...</div>

        <div v-else-if="templates.length === 0" class="empty-state">
          <i class="pi pi-file-edit empty-icon"></i>
          <p class="empty-title">No hay plantillas registradas.</p>
          <p class="empty-sub">Usa <strong>Sincronizar con Meta</strong> para importarlas automáticamente.</p>
        </div>

        <table v-else class="tpl-table">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Estado</th>
              <th>Calidad</th>
              <th>Idioma</th>
              <th class="col-center">Activa</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in templates" :key="t.id"
              @click="selectTemplate(t)"
              :class="['tpl-row', selected?.id === t.id ? 'tpl-row--selected' : '', !t.is_active ? 'tpl-row--inactive' : '']">
              <td><code class="tpl-name">{{ t.name }}</code></td>
              <td><Tag :value="statusLabel(t.status)" :severity="statusSeverity(t.status)" /></td>
              <td>
                <span v-if="t.quality_score" class="quality-badge">
                  <span class="quality-dot" :class="`quality-dot--${t.quality_score?.toLowerCase()}`"></span>
                  {{ t.quality_score }}
                </span>
                <span v-else class="muted">—</span>
              </td>
              <td class="muted">{{ t.language_code }}</td>
              <td class="col-center">
                <ToggleSwitch :modelValue="t.is_active" @update:modelValue="toggleActive(t, $event)" @click.stop />
              </td>
              <td class="col-right">
                <Button icon="pi pi-trash" text severity="danger" size="small" @click.stop="confirmDelete(t)" />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Preview derecho -->
      <div class="preview-panel">
        <p class="preview-label">Vista previa</p>

        <div v-if="!selected" class="preview-empty">
          <i class="pi pi-eye"></i>
          <span>Selecciona una plantilla</span>
        </div>

        <div v-else class="wa-preview">
          <div class="wa-bubble">
            <!-- Header imagen -->
            <img v-if="selected.header_image_url" :src="selected.header_image_url" class="wa-header-img" />
            <div v-else-if="selected.header_type === 'IMAGE'" class="wa-header-placeholder">
              <i class="pi pi-image"></i>
            </div>
            <!-- Header texto -->
            <div v-if="selected.header_type === 'TEXT' && selected.header_text" class="wa-header-text">
              {{ selected.header_text }}
            </div>
            <!-- Body -->
            <div class="wa-body" v-html="renderBody(selected.body_text)"></div>
            <!-- Footer -->
            <div v-if="selected.footer_text" class="wa-footer">{{ selected.footer_text }}</div>
            <!-- Timestamp -->
            <div class="wa-time">9:43 pm ✓✓</div>
            <!-- Botones -->
            <template v-if="selected.buttons?.length">
              <div class="wa-divider"></div>
              <div v-for="btn in selected.buttons" :key="btn.text" class="wa-btn">↩ {{ btn.text }}</div>
            </template>
          </div>
          <!-- Info extra -->
          <div v-if="selected.rejection_reason" class="preview-rejection">
            <strong>Rechazada:</strong> {{ selected.rejection_reason }}
          </div>
        </div>
      </div>
    </div>

    <ConfirmDialog />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useToast }   from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { api } from '../api.js';
import Button        from 'primevue/button';
import Tag           from 'primevue/tag';
import ToggleSwitch  from 'primevue/toggleswitch';
import ConfirmDialog from 'primevue/confirmdialog';

const toast   = useToast();
const confirm = useConfirm();

const templates = ref([]);
const selected  = ref(null);
const loading   = ref(false);
const syncing   = ref(false);

const alertTemplates = computed(() =>
  templates.value.filter(t =>
    t.status === 'rejected' || t.status === 'paused' ||
    t.quality_score === 'RED' || t.quality_score === 'YELLOW'
  )
);

onMounted(loadTemplates);

async function loadTemplates() {
  loading.value = true;
  const res = await api.templates();
  if (res.status === 'ok') templates.value = res.data;
  loading.value = false;
}

async function syncTemplates() {
  syncing.value = true;
  const res = await api.syncTemplates();
  if (res.status === 'ok') {
    templates.value = res.data;
    toast.add({ severity: 'success', summary: `${res.synced} plantillas sincronizadas`, life: 3000 });
  } else {
    toast.add({ severity: 'error', summary: 'Error al sincronizar', detail: res.message, life: 5000 });
  }
  syncing.value = false;
}

function selectTemplate(t) {
  selected.value = selected.value?.id === t.id ? null : t;
}

async function toggleActive(template, value) {
  const res = await api.updateTemplate(template.id, { is_active: value });
  if (res.status === 'ok') template.is_active = value;
}

function confirmDelete(template) {
  confirm.require({
    message: `¿Eliminar "${template.name}"? Solo la quita del panel, no de Meta.`,
    header: 'Eliminar plantilla', icon: 'pi pi-trash',
    acceptLabel: 'Eliminar', rejectLabel: 'Cancelar', acceptClass: 'p-button-danger',
    accept: () => deleteTemplate(template),
  });
}

async function deleteTemplate(template) {
  const res = await api.deleteTemplate(template.id);
  if (res.status === 'ok') {
    templates.value = templates.value.filter(t => t.id !== template.id);
    if (selected.value?.id === template.id) selected.value = null;
    toast.add({ severity: 'success', summary: 'Plantilla eliminada', life: 3000 });
  }
}

function renderBody(text) {
  if (!text) return '';
  return text
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/\*(.*?)\*/g, '<strong>$1</strong>')
    .replace(/_(.*?)_/g, '<em>$1</em>')
    .replace(/\n/g, '<br>');
}

function statusLabel(s) {
  return { approved: 'Aprobada', rejected: 'Rechazada', pending: 'Pendiente', paused: 'Pausada', disabled: 'Desactivada' }[s] ?? s;
}
function statusSeverity(s) {
  return { approved: 'success', rejected: 'danger', pending: 'warn', paused: 'warn', disabled: 'secondary' }[s] ?? 'secondary';
}
</script>

<style scoped>
.templates-page { display: flex; flex-direction: column; gap: 16px; }

.page-header    { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
.page-title     { font-size: 1.25rem; font-weight: 700; color: var(--p-text-color); margin: 0; }
.page-subtitle  { font-size: .82rem; color: var(--p-text-muted-color); margin: 4px 0 0; }
.header-actions { display: flex; gap: 8px; flex-shrink: 0; }

/* Alertas */
.alert-banner {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 16px; border-radius: 10px; font-size: .85rem; border: 1px solid;
}
.alert-danger { background: var(--p-red-50);    border-color: var(--p-red-200);    color: var(--p-red-800); }
.alert-warn   { background: var(--p-orange-50); border-color: var(--p-orange-200); color: var(--p-orange-800); }

/* Layout dos columnas */
.main-layout {
  display: grid;
  grid-template-columns: 1fr 280px;
  gap: 20px;
  align-items: start;
}

/* Tabla */
.table-panel {
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: 12px;
  overflow: hidden;
}

.empty-state  { padding: 48px 24px; text-align: center; color: var(--p-text-muted-color); }
.empty-icon   { font-size: 2.5rem; display: block; margin-bottom: 12px; }
.empty-title  { font-weight: 600; margin: 0 0 4px; }
.empty-sub    { font-size: .82rem; margin: 0; }

.tpl-table    { width: 100%; border-collapse: collapse; font-size: .875rem; }
.tpl-table th {
  text-align: left; padding: 10px 16px;
  background: var(--p-surface-50); border-bottom: 1px solid var(--p-content-border-color);
  font-weight: 600; color: var(--p-text-muted-color); font-size: .8rem;
}
.tpl-row { border-bottom: 1px solid var(--p-content-border-color); cursor: pointer; transition: background .12s; }
.tpl-row:last-child   { border-bottom: none; }
.tpl-row:hover        { background: var(--p-surface-50); }
.tpl-row--selected    { background: var(--p-primary-50) !important; }
.tpl-row--inactive    { opacity: .5; }
.tpl-row td           { padding: 10px 16px; }

.tpl-name   { background: var(--p-surface-100); padding: 2px 8px; border-radius: 4px; font-size: .75rem; font-family: monospace; }
.col-center { text-align: center; }
.col-right  { text-align: right; }
.muted      { color: var(--p-text-muted-color); font-size: .8rem; }

.quality-badge { display: flex; align-items: center; gap: 6px; font-size: .8rem; }
.quality-dot   { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
.quality-dot--green  { background: var(--p-green-500); }
.quality-dot--yellow { background: var(--p-orange-400); }
.quality-dot--red    { background: var(--p-red-500); }

/* Preview */
.preview-panel  { display: flex; flex-direction: column; gap: 10px; }
.preview-label  { font-size: .8rem; font-weight: 600; color: var(--p-text-muted-color); margin: 0; }

.preview-empty {
  background: var(--p-content-background);
  border: 1px solid var(--p-content-border-color);
  border-radius: 12px;
  padding: 40px 16px;
  text-align: center;
  color: var(--p-text-muted-color);
  display: flex; flex-direction: column; align-items: center; gap: 8px; font-size: .85rem;
}
.preview-empty .pi { font-size: 2rem; }

/* WhatsApp bubble */
.wa-preview   { display: flex; flex-direction: column; gap: 10px; }
.wa-preview   { background: #e5ddd5; border-radius: 12px; padding: 16px; }

.wa-bubble {
  background: #fff;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,.12);
  font-size: .8rem;
}
.wa-header-img     { width: 100%; max-height: 140px; object-fit: cover; display: block; }
.wa-header-placeholder {
  height: 100px; background: var(--p-surface-100);
  display: flex; align-items: center; justify-content: center;
  color: var(--p-text-muted-color); font-size: 2rem;
}
.wa-header-text { padding: 10px 12px 0; font-weight: 700; font-size: .82rem; color: #111; }
.wa-body        { padding: 8px 12px; color: #333; line-height: 1.5; white-space: pre-wrap; }
.wa-footer      { padding: 0 12px 6px; font-size: .72rem; color: #888; }
.wa-time        { padding: 0 12px 8px; text-align: right; font-size: .68rem; color: #aaa; }
.wa-divider     { border-top: 1px solid #f0f0f0; }
.wa-btn         { text-align: center; padding: 8px; color: #0a8dcc; font-size: .78rem; font-weight: 500; border-bottom: 1px solid #f0f0f0; }
.wa-btn:last-child { border-bottom: none; }

.preview-rejection {
  background: var(--p-red-50); border: 1px solid var(--p-red-200);
  border-radius: 8px; padding: 8px 12px; font-size: .78rem; color: var(--p-red-800);
}

</style>
