<template>
  <div class="templates-page">

    <!-- Header -->
    <div class="page-header">
      <div>
        <h2 class="page-title">Plantillas de mensaje</h2>
        <p class="page-subtitle">
          {{ channel === 'whatsapp'
            ? 'Solo se usan plantillas aprobadas por Meta para los envíos de WhatsApp.'
            : 'Plantillas de SMS locales: las creas aquí y se usan de inmediato.' }}
        </p>
      </div>
      <div class="header-actions">
        <Button v-if="channel === 'whatsapp'" label="Sincronizar con Meta" icon="pi pi-refresh" severity="secondary" raised :loading="syncing" @click="syncTemplates" />
      </div>
    </div>

    <!-- Selector de canal -->
    <SelectButton
      v-model="channel"
      :options="channelOptions"
      option-label="label"
      option-value="value"
      :allow-empty="false"
      class="channel-switch"
    />

    <!-- Panel SMS -->
    <SmsTemplatesPanel v-if="channel === 'sms'" />

    <!-- ── WhatsApp ── -->
    <template v-else>
    <!-- Alertas de calidad -->
    <div v-for="t in alertTemplates" :key="`alert-${t.id}`" class="alert-banner"
      :class="t.status === 'rejected' || t.quality_score === 'RED' ? 'alert-danger' : 'alert-warn'">
      <i class="pi" :class="t.status === 'rejected' ? 'pi-times-circle' : 'pi-exclamation-triangle'"></i>
      <span>
        <strong>{{ t.name }}</strong>:
        <span v-if="t.status === 'rejected'">Rechazada por Meta - {{ t.rejection_reason || 'sin motivo indicado' }}</span>
        <span v-else-if="t.status === 'paused'">Pausada por Meta</span>
        <span v-else>Calidad {{ t.quality_score }} - puede afectar entregas</span>
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

        <div v-else class="table-scroll">
        <table class="tpl-table">
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
              :class="['tpl-row', selected?.id === t.id ? 'tpl-row--selected' : '', !t.is_active ? 'tpl-row--inactive' : '', t.is_hidden ? 'tpl-row--hidden' : '']">
              <td>
                <code class="tpl-name">{{ t.name }}</code>
                <Tag v-if="t.is_hidden" value="Oculta" severity="secondary" class="hidden-tag" />
                <Tag v-if="t.needs_image" value="Falta imagen" severity="danger" class="hidden-tag" />
              </td>
              <td><Tag :value="statusLabel(t.status)" :severity="statusSeverity(t.status)" /></td>
              <td>
                <span v-if="t.quality_score" class="quality-badge">
                  <span class="quality-dot" :class="`quality-dot--${t.quality_score?.toLowerCase()}`"></span>
                  {{ t.quality_score }}
                </span>
                <span v-else class="muted">-</span>
              </td>
              <td class="muted">{{ t.language_code }}</td>
              <td class="col-center">
                <ToggleSwitch :modelValue="t.is_active" @update:modelValue="toggleActive(t, $event)" @click.stop />
              </td>
              <td class="col-right">
                <Button
                  v-if="isSuperAdmin"
                  :icon="t.is_hidden ? 'pi pi-eye-slash' : 'pi pi-eye'"
                  text severity="secondary" size="small"
                  v-tooltip.top="t.is_hidden ? 'Oculta - clic para mostrarla' : 'Visible - clic para ocultarla'"
                  @click.stop="toggleVisibility(t)"
                />
                <Button icon="pi pi-trash" text severity="danger" size="small" @click.stop="confirmDelete(t)" />
              </td>
            </tr>
          </tbody>
        </table>
        </div>
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
            <!-- Header imagen: se prefiere la local, que es la que Meta sí entrega -->
            <img v-if="selected.image_url || selected.header_image_url" :src="selected.image_url || selected.header_image_url" class="wa-header-img" />
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
          <!-- Motivo del rechazo: solo si de verdad está rechazada. El estado ya se ve en la
               tabla, así que aquí solo aporta el motivo. -->
          <div v-if="selected.status === 'rejected'" class="preview-rejection">
            <strong>Motivo del rechazo:</strong> {{ selected.rejection_reason || 'Meta no indicó un motivo.' }}
          </div>

          <!-- Imagen del encabezado: la que Meta guarda es solo vista previa, no la entrega -->
          <div v-if="isAdmin && selected.header_type === 'IMAGE'" class="img-manager">
            <p class="img-manager-title">
              Imagen del encabezado
              <HelpPopover
                title="Por qué se sube aquí"
                :items="imageHelpItems"
                warning="Sin esta imagen la plantilla no se puede usar en campañas."
              />
            </p>

            <Message v-if="selected.needs_image" severity="warn" class="img-missing">
              Falta la imagen. Súbela para poder usar esta plantilla.
            </Message>

            <input
              ref="imageInput"
              type="file"
              accept=".jpg,.jpeg,.png"
              class="img-input"
              @change="uploadImage"
            />
            <div class="img-actions">
              <Button
                :label="selected.image_url ? 'Reemplazar imagen' : 'Subir imagen'"
                icon="pi pi-upload"
                size="small"
                :loading="uploadingImage"
                @click="imageInput?.click()"
              />
              <Button
                v-if="selected.image_url"
                label="Quitar"
                icon="pi pi-trash"
                text severity="danger" size="small"
                @click="removeImage"
              />
            </div>
          </div>
          <!-- Enviar prueba - solo admin/superadmin. Sin la imagen local el mensaje saldría
               fallido (Meta no entrega la URL de su CDN), así que ni se ofrece. -->
          <Button
            v-if="selected.status === 'approved' && isAdmin"
            label="Enviar prueba"
            icon="pi pi-send"
            size="small"
            severity="secondary"
            class="test-btn"
            :disabled="selected.needs_image"
            v-tooltip.top="selected.needs_image ? 'Sube la imagen del encabezado para poder probar esta plantilla' : null"
            @click="openTestDialog"
          />
        </div>
      </div>
    </div>

    <ConfirmDialog />

    <!-- Dialog: Enviar mensaje de prueba -->
    <Dialog v-model:visible="showTestDialog" header="Enviar mensaje de prueba" :modal="true" :style="{ width: '400px' }">
      <div class="test-form">
        <div class="test-notice">
          <i class="pi pi-info-circle" />
          <span>Es un mensaje real, igual que el de una campaña.</span>
          <HelpPopover
            title="Qué pasa al enviar una prueba"
            :items="testHelpItems"
            warning="El contacto quedará en enfriamiento: no podrá recibir campañas durante ese periodo."
            tip="Usa siempre el mismo número de pruebas, y que no esté en los segmentos de tus campañas reales."
          />
        </div>
        <div class="form-group">
          <label>Plantilla</label>
          <code class="tpl-chip">{{ selected?.name }}</code>
        </div>
        <div class="form-group">
          <label>Contacto destino</label>
          <Select
            v-model="testForm.to"
            :options="contactOptions"
            option-label="label"
            option-value="value"
            placeholder="Selecciona un contacto activo"
            fluid
            :loading="loadingContacts"
            filter
          />
        </div>
        <template v-if="templateVarLabels.length">
          <div v-for="(varName, idx) in templateVarLabels" :key="idx" class="form-group">
            <label>{{ varName }}</label>
            <InputText v-model="testForm.vars[idx]" :placeholder="varName" fluid />
          </div>
        </template>
        <p v-else class="no-vars">Esta plantilla no tiene variables.</p>
        <Message v-if="testResult" :severity="testResult.status === 'sent' ? 'success' : 'error'" class="test-result">
          {{ testResult.status === 'sent'
              ? 'Mensaje enviado correctamente'
              : (testResult.wa_response?.error?.message || testResult.message || 'Error al enviar') }}
        </Message>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="showTestDialog = false" />
        <Button
          label="Enviar"
          icon="pi pi-send"
          :loading="testSending"
          :disabled="!testForm.to || testSending"
          @click="sendTest"
        />
      </template>
    </Dialog>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useToast }   from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { api }     from '../api.js';
import { useAuth } from '../auth.js';
import Button        from 'primevue/button';
import Tag           from 'primevue/tag';
import ToggleSwitch  from 'primevue/toggleswitch';
import ConfirmDialog from 'primevue/confirmdialog';
import Dialog        from 'primevue/dialog';
import Select        from 'primevue/select';
import SelectButton  from 'primevue/selectbutton';
import InputText     from 'primevue/inputtext';
import Message       from 'primevue/message';
import SmsTemplatesPanel from '../components/SmsTemplatesPanel.vue';
import HelpPopover       from '../components/HelpPopover.vue';

const toast   = useToast();
const confirm = useConfirm();
const { user: authState } = useAuth();
const isAdmin = computed(() => ['admin', 'superadmin'].includes(authState.user?.role));
const isSuperAdmin = computed(() => authState.user?.role === 'superadmin');

const channel = ref('whatsapp');
const channelOptions = [
  { label: 'WhatsApp', value: 'whatsapp' },
  { label: 'SMS',      value: 'sms' },
];

const templates = ref([]);
const selected  = ref(null);
const loading   = ref(false);
const syncing   = ref(false);

// ── Enviar prueba ─────────────────────────────────────────────
const showTestDialog  = ref(false);
const testForm        = ref({ to: '', vars: [] });
const testSending     = ref(false);
const testResult      = ref(null);
const contactOptions  = ref([]);
const loadingContacts = ref(false);

const templateVarLabels = computed(() => extractVarLabels(selected.value?.body_text));

const imageInput     = ref(null);
const uploadingImage = ref(false);

const imageHelpItems = [
    { icon: 'pi-image',   label: 'Por qué',    text: 'la imagen que guarda Meta al aprobar la plantilla solo sirve de vista previa; al enviar no la entrega.' },
    { icon: 'pi-upload',  label: 'Qué subir',  text: 'la misma imagen que registraste en Meta, en JPG o PNG y de menos de 5 MB.' },
    { icon: 'pi-check',   label: 'Cuándo',     text: 'una sola vez por plantilla. Si la cambias en Meta, reemplázala aquí también.' },
];

// La prueba no es gratis ni inocua: sale por el mismo número, gasta cupo y congela al contacto.
// Sin monto a propósito: el precio lo fija Meta por país y categoría, y cambia.
const testHelpItems = [
    { icon: 'pi-send',         label: 'Es real',      text: 'sale por el número de la empresa, igual que una campaña. El contacto lo recibe en su WhatsApp.' },
    { icon: 'pi-gauge',        label: 'Gasta cupo',   text: 'cuenta dentro del límite de mensajes del día.' },
    { icon: 'pi-clock',        label: 'Enfriamiento', text: 'el contacto queda en espera y no recibirá campañas hasta que pase el periodo configurado.' },
    { icon: 'pi-receipt',      label: 'Se factura',   text: 'Meta la cobra como cualquier mensaje de campaña y aparece en la factura de la cuenta.' },
    { icon: 'pi-comments',     label: 'Si responde',  text: 'la conversación de las siguientes 24 horas no gasta cupo ni se cobra aparte.' },
    { icon: 'pi-ban',          label: 'Bajas',        text: 'un contacto que pidió su baja nunca aparece aquí y el sistema rechaza el envío.' },
];

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
    // El sync espeja lo que hay en Meta: si una plantilla se borró allá, aquí también se quita.
    const retiradas = res.removed
      ? `${res.removed} retirada${res.removed === 1 ? '' : 's'} (ya no están en Meta)`
      : null;
    toast.add({
      severity : 'success',
      summary  : `${res.synced} plantillas sincronizadas`,
      detail   : retiradas,
      life     : 4000,
    });
  } else {
    toast.add({ severity: 'error', summary: 'Error al sincronizar', detail: res.message, life: 5000 });
  }
  syncing.value = false;
}

async function uploadImage(event) {
  const file = event.target.files?.[0];
  if (!file || !selected.value) return;

  uploadingImage.value = true;
  let res;
  try {
    res = await api.uploadTemplateImage(selected.value.id, file);
  } catch {
    // Red de red: si la petición ni siquiera llega (sin conexión), el botón no se queda girando.
    res = { status: 'error', message: 'No se pudo contactar al servidor.' };
  } finally {
    uploadingImage.value = false;
    event.target.value = ''; // permite volver a elegir el mismo archivo
  }

  if (res.status === 'ok') {
    applyImageResult(res.data);
    toast.add({ severity: 'success', summary: 'Imagen guardada', life: 3000 });
  } else {
    // Los errores de validación de Laravel vienen en `errors`, no en `message`.
    const detail = res.errors?.image?.[0] ?? res.message ?? 'No se pudo subir la imagen';
    toast.add({ severity: 'error', summary: 'Error al subir', detail, life: 5000 });
  }
}

async function removeImage() {
  if (!selected.value) return;

  const res = await api.deleteTemplateImage(selected.value.id);

  if (res.status === 'ok') {
    applyImageResult(res.data);
    toast.add({ severity: 'success', summary: 'Imagen quitada', life: 3000 });
  } else {
    toast.add({ severity: 'error', summary: 'Error al quitar la imagen', detail: res.message, life: 5000 });
  }
}

/** Refleja el nuevo estado de la imagen en la fila y en la vista previa, sin recargar todo. */
function applyImageResult(data) {
  const row = templates.value.find(t => t.id === data.id);
  if (row) Object.assign(row, { image_url: data.image_url, needs_image: data.needs_image });
  if (selected.value?.id === data.id) Object.assign(selected.value, { image_url: data.image_url, needs_image: data.needs_image });
}

function selectTemplate(t) {
  selected.value = selected.value?.id === t.id ? null : t;
}

async function toggleActive(template, value) {
  const res = await api.updateTemplate(template.id, { is_active: value });
  if (res.status === 'ok') template.is_active = value;
}

// Mostrar/ocultar (solo superadmin). Oculta = fuera de la vista del operador y del
// selector de campanas. NO borra la plantilla.
async function toggleVisibility(template) {
  const next = !template.is_hidden;
  const res = await api.setTemplateVisibility(template.id, next);
  if (res.status === 'ok') {
    template.is_hidden = next;
    toast.add({
      severity: 'success',
      summary : next ? 'Plantilla oculta' : 'Plantilla visible',
      detail  : next ? 'Ya no aparece al operador ni en campanas.' : 'Vuelve a estar disponible.',
      life    : 3000,
    });
  } else {
    toast.add({ severity: 'error', summary: 'Error', detail: res.message, life: 4000 });
  }
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

async function loadContactOptions() {
  loadingContacts.value = true;
  const data = await api.contacts({ status: 'active', per_page: 200 });
  contactOptions.value = (data.data ?? []).map(c => ({
    label: c.name ? `${c.name} - ${c.phone}` : c.phone,
    value: c.phone,
  }));
  loadingContacts.value = false;
}

function openTestDialog() {
  testForm.value  = { to: '', vars: Array(templateVarLabels.value.length).fill('') };
  testResult.value = null;
  showTestDialog.value = true;
  if (!contactOptions.value.length) loadContactOptions();
}

async function sendTest() {
  testSending.value  = true;
  testResult.value   = null;
  testResult.value   = await api.sendTest({
    template_name : selected.value.name,
    language_code : selected.value.language_code,
    to            : testForm.value.to,
    body_vars     : testForm.value.vars.filter(v => v !== ''),
  });
  testSending.value = false;
}

function extractVarLabels(bodyText) {
  if (!bodyText) return [];
  const matches = [...bodyText.matchAll(/\{\{([^}]+)\}\}/g)];
  return matches.map(m => {
    const inner = m[1].trim();
    return /^\d+$/.test(inner) ? `Variable ${inner}` : inner;
  });
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
.channel-switch { align-self: flex-start; }

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
.table-scroll { overflow-x: auto; }

/* Móvil: apilar lista y vista previa; la tabla scrollea dentro de su panel. */
@media (max-width: 768px) {
  .main-layout { grid-template-columns: 1fr; }
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
.tpl-row--hidden      { background: var(--p-surface-50); }
.tpl-row--hidden .tpl-name { opacity: .6; }
.tpl-row td           { padding: 10px 16px; }

.hidden-tag { margin-left: 6px; font-size: .68rem; vertical-align: middle; }

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

.test-btn { width: 100%; margin-top: 10px; }

/* Dialog: enviar prueba */
.img-manager {
    margin-top: 14px;
    padding-top: 12px;
    border-top: 1px solid var(--p-surface-200);
}
.img-manager-title {
    display: flex;
    align-items: center;
    gap: 4px;
    margin: 0 0 6px;
    font-size: .78rem;
    font-weight: 600;
    color: var(--p-text-color);
}
.img-missing  { margin-bottom: 8px; font-size: .78rem; }
.img-input    { display: none; }
.img-actions  { display: flex; gap: 6px; flex-wrap: wrap; }

.test-form     { display: flex; flex-direction: column; gap: 4px; }
.test-notice {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 12px;
    padding: 8px 10px;
    border-radius: 6px;
    background: var(--p-surface-100);
    color: var(--p-text-muted-color);
    font-size: .8rem;
}
.test-notice .pi-info-circle { color: var(--p-primary-500); }
.form-group    { margin-bottom: 10px; }
.form-group label { display: block; font-size: .82rem; color: var(--p-text-muted-color); margin-bottom: 4px; }
.tpl-chip  {
  display: inline-block; background: var(--p-surface-100);
  padding: 2px 8px; border-radius: 4px; font-size: .78rem; font-family: monospace;
}
.no-vars     { font-size: .8rem; color: var(--p-text-muted-color); margin: 0 0 10px; }
.test-result { margin-top: 12px; }

</style>
