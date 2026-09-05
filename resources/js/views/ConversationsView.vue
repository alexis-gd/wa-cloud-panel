<template>
  <div class="conv-page" :class="{ 'conv-page--detail': selected }">

    <!-- Panel izquierdo: lista de contactos -->
    <div class="conv-sidebar">
      <div class="sidebar-header">
        <span class="sidebar-title">Conversaciones</span>
        <span class="sidebar-count">{{ contacts.length }} contactos</span>
      </div>

      <div class="sidebar-list">
        <div v-if="loadingContacts" class="sidebar-empty">Cargando...</div>
        <div v-else-if="contacts.length === 0" class="sidebar-empty">Sin conversaciones aún</div>

        <ConversationListItem
          v-for="c in contacts"
          :key="c.id"
          :contact="c"
          :active="selected?.id === c.id"
          :current-user-id="authState.user?.id ?? null"
          @select="selectContact"
        />
      </div>
    </div>

    <!-- Panel central: chat -->
    <div class="conv-chat">
      <div v-if="!selected" class="chat-empty">
        <i class="pi pi-comments chat-empty-icon"></i>
        <p>Selecciona una conversación</p>
      </div>

      <template v-else>
        <!-- Topbar del chat -->
        <div class="chat-header">
          <Button icon="pi pi-arrow-left" text rounded class="chat-back" @click="selected = null" aria-label="Volver a la lista" />
          <div>
            <span class="chat-name">{{ selected.name || selected.phone }}</span>
            <span class="chat-phone">{{ selected.phone }}</span>
          </div>
          <div class="chat-badges">
            <Tag v-if="selected.status==='opted_out'" value="Baja permanente" severity="danger" />
            <Tag v-else-if="selected.snoozed_until" :value="`Pospuesto hasta ${formatDate(selected.snoozed_until)}`" severity="warn" />
            <Tag v-else-if="!windowOpen"            value="Ventana cerrada - el cliente debe responder primero" severity="warn" />
            <Tag v-else                             value="Abierta" severity="success" />
          </div>
        </div>

        <!-- Mensajes -->
        <div ref="messagesContainer" class="chat-messages">
          <div v-if="loadingChat" class="chat-loading">Cargando mensajes...</div>
          <div v-for="msg in messages" :key="msg.id"
            :class="['msg-row', msg.direction === 'outbound' ? 'msg-row--out' : 'msg-row--in']">
            <div :class="['msg-bubble', msg.direction === 'outbound' ? 'msg-bubble--out' : 'msg-bubble--in']">
              <p class="msg-text">{{ msg.body }}</p>
              <div class="msg-meta">
                <span>{{ formatTime(msg.created_at) }}</span>
                <i v-if="msg.direction==='outbound'" class="pi msg-status"
                  :class="{ 'pi-check': msg.status==='sent', 'pi-check-circle': msg.status==='delivered', 'pi-eye': msg.status==='read' }"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- Input. Vive en su propio componente para que teclear no vuelva a dibujar la
             lista lateral entera: con cientos de conversaciones se sentia el retraso. -->
        <ConversationComposer
          ref="composer"
          :window-open="windowOpen"
          :opted-out="selected.status === 'opted_out'"
          :sending="sending"
          :quick-replies="quickReplies"
          @send="sendMessage"
        />
      </template>
    </div>

    <!-- Panel derecho: info + asignación + quick replies admin -->
    <div v-if="selected" class="conv-info">
      <div class="info-section">
        <p class="info-title">Info del contacto</p>
        <div class="info-rows">
          <div class="info-row"><span class="info-lbl">Nombre</span><span class="info-val">{{ selected.name || '-' }}</span></div>
          <div class="info-row"><span class="info-lbl">Teléfono</span><span class="info-val">{{ selected.phone }}</span></div>
          <div class="info-row"><span class="info-lbl">Estado</span><span class="info-val">{{ contactStatusLabel(selected.status) }}</span></div>
          <div v-if="selected.snoozed_until" class="info-row">
            <span class="info-lbl">Pospuesto</span>
            <span class="info-val info-val--warn">{{ formatDate(selected.snoozed_until) }}</span>
          </div>
        </div>
      </div>

      <!-- Asignación de agente -->
      <div class="info-section">
        <p class="info-title">Asignación</p>
        <div class="assign-current" v-if="currentAssignment">
          <i class="pi pi-user assign-icon"></i>
          <span class="assign-name">{{ currentAssignment.name }}</span>
        </div>
        <p v-else class="assign-empty">Sin asignar</p>
        <!-- Claim: cualquier agente puede tomarse la conv -->
        <Button label="Tomar conversación" icon="pi pi-hand-pointer" size="small" severity="secondary"
          class="assign-btn" :loading="claiming" @click="claimConversation" />
        <!-- Reasignar: solo admin/operator -->
        <template v-if="isAdminOrOperator">
          <Select
            v-model="assignUserId"
            :options="users"
            option-label="name"
            option-value="id"
            placeholder="Asignar a..."
            size="small"
            fluid
            class="assign-select"
          />
          <Button
            :label="currentAssignment ? 'Reasignar' : 'Asignar'"
            size="small"
            :loading="assigning"
            :disabled="!assignUserId"
            @click="doAssign"
            class="assign-btn"
          />
          <Button
            v-if="currentAssignment"
            label="Dejar sin asignar"
            icon="pi pi-user-minus"
            size="small"
            severity="secondary"
            text
            :loading="releasing"
            @click="doRelease"
            class="assign-btn"
          />
          <Button
            label="Ver historial"
            icon="pi pi-history"
            size="small"
            severity="secondary"
            text
            :loading="loadingHistory"
            @click="openHistory"
            class="assign-btn"
          />
        </template>
      </div>

      <div class="info-section info-section--grow">
        <div class="info-title-row">
          <p class="info-title">Respuestas rápidas</p>
          <Button v-if="isAdmin" icon="pi pi-plus" text size="small" @click="showNewQR = true" />
        </div>
        <div class="qr-list">
          <div v-for="qr in quickReplies" :key="qr.id" class="qr-item">
            <div class="qr-item-body">
              <p class="qr-item-title">{{ qr.title }}</p>
              <p class="qr-item-text">{{ qr.body }}</p>
            </div>
            <Button v-if="isAdmin" icon="pi pi-trash" text severity="danger" size="small" @click="deleteQR(qr.id)" />
          </div>
        </div>
      </div>
    </div>

    <!-- Dialog nueva quick reply -->
    <Dialog v-model:visible="showNewQR" header="Nueva respuesta rápida" modal style="width:400px">
      <div class="form-grid">
        <div class="form-field">
          <label>Título</label>
          <InputText v-model="newQR.title" placeholder="Ej: Confirmar visita" fluid />
        </div>
        <div class="form-field">
          <label>Mensaje</label>
          <Textarea v-model="newQR.body" placeholder="Texto del mensaje..." :autoResize="true" rows="3" fluid />
        </div>
      </div>
      <template #footer>
        <Button label="Cancelar" text @click="showNewQR = false" />
        <Button label="Guardar" :loading="savingQR" @click="saveQR" />
      </template>
    </Dialog>

  </div>

  <!-- Historial de asignaciones: quién la tuvo, quién la movió y cuándo -->
  <Dialog v-model:visible="historyDialog" header="Historial de la conversación" modal style="width: 560px">
    <p class="history-contact" v-if="selected">
      {{ selected.name || 'Sin nombre' }} · <code>{{ selected.phone }}</code>
    </p>

    <p v-if="!history.length" class="history-empty">
      Esta conversación no tiene movimientos de asignación todavía.
    </p>

    <ul v-else class="history-list">
      <li v-for="mov in history" :key="mov.id" class="history-item">
        <span class="history-dot" :class="'dot--' + mov.action"></span>
        <div class="history-body">
          <div class="history-head">
            <strong>{{ mov.action_label }}</strong>
            <span v-if="mov.agent" class="history-agent">→ {{ mov.agent }}</span>
          </div>
          <div class="history-meta">
            {{ mov.at }}
            <template v-if="mov.by"> · por {{ mov.by }}</template>
            <template v-else> · por el sistema</template>
          </div>
        </div>
      </li>
    </ul>

    <template #footer>
      <Button label="Cerrar" text @click="historyDialog = false" />
    </template>
  </Dialog>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue';
import { useToast } from 'primevue/usetoast';
import { useAuth }  from '../auth.js';
import { api }      from '../api.js';
import { initEcho } from '../echo.js';
import Button    from 'primevue/button';
import Textarea  from 'primevue/textarea';
import InputText from 'primevue/inputtext';
import Select    from 'primevue/select';
import Tag       from 'primevue/tag';
import Dialog    from 'primevue/dialog';
import ConversationListItem from '../components/ConversationListItem.vue';
import ConversationComposer from '../components/ConversationComposer.vue';

const toast = useToast();
const { user: authState } = useAuth();
const isAdmin            = computed(() => ['admin', 'superadmin'].includes(authState.user?.role));
const isAdminOrOperator  = computed(() => ['admin', 'operator', 'superadmin'].includes(authState.user?.role));

// ── Estado del contacto (identidad, no de la conversacion) ───────────────────
// Es el `contacts.status` de la BD. Viaja en ingles porque es un identificador; aqui se
// traduce para el panel de info, donde antes salia crudo ("opted_out").
const CONTACT_STATUS = {
  active     : 'Activo',
  opted_out  : 'Baja',
  invalid    : 'Inválido',
  unreachable: 'Inalcanzable',
};

function contactStatusLabel(status) {
  return CONTACT_STATUS[status] ?? status;
}

const contacts     = ref([]);
const selected     = ref(null);
const messages     = ref([]);
const windowOpen   = ref(false);
const quickReplies = ref([]);
const composer     = ref(null);   // ConversationComposer, para poder limpiarlo al enviar
const sending      = ref(false);
const loadingContacts  = ref(false);
const loadingChat      = ref(false);
const messagesContainer = ref(null);
const showNewQR = ref(false);
const savingQR  = ref(false);
const newQR     = ref({ title: '', body: '' });

// Multi-agente
const users            = ref([]);
const currentAssignment = ref(null);
const assignUserId     = ref(null);
const assigning        = ref(false);
const releasing        = ref(false);

// Historial de movimientos (P2): para cambios de turno y para medir el seguimiento.
const historyDialog    = ref(false);
const history          = ref([]);
const loadingHistory   = ref(false);
const claiming         = ref(false);

let echoChannel = null;
let listRefetchDebounce = null;
let chatRefetchDebounce = null;

onMounted(async () => {
  const promises = [loadContacts(), loadQuickReplies()];
  if (isAdminOrOperator.value) promises.push(loadUsers());
  await Promise.all(promises);
  subscribeRealtime();
});

onUnmounted(() => {
  clearTimeout(listRefetchDebounce);
  clearTimeout(chatRefetchDebounce);
  if (echoChannel) {
    echoChannel.stopListening('.inbound.message');
    echoChannel.stopListening('.conversation.updated');
    echoChannel = null;
  }
});

// Tiempo real (Soketi): todo lo de una conversacion se actualiza solo, sin recargar.
// Dos eventos por el mismo canal:
//  - .inbound.message: respuesta nueva de un contacto (toast + refresca chat y lista).
//  - .conversation.updated: cambio de estado/asignacion/entrega (refetch dirigido, sin flicker).
// Si no hay servidor WS, initEcho() devuelve null y no pasa nada (se ve al reabrir, como antes).
function subscribeRealtime() {
  const echo = initEcho();
  if (! echo) return;

  echoChannel = echo.private('conversations');

  echoChannel.listen('.inbound.message', (e) => {
    // Esta vista es solo WhatsApp; las respuestas SMS las maneja SmsRepliesView.
    if (e.channel === 'sms') return;
    // Si el chat abierto es de ese contacto, recargar sus mensajes (y bajar al final).
    if (selected.value && e.contact_id === selected.value.id) {
      refreshOpenChat(true);
    }
    loadContacts(true); // refresca la lista sin spinner
    toast.add({
      severity: 'info',
      summary : 'Nueva respuesta',
      detail  : `${e.contact_name || 'Contacto'}: ${(e.body ?? '').slice(0, 40)}`,
      life    : 4000,
    });
  });

  echoChannel.listen('.conversation.updated', (e) => {
    // Estado/asignacion/entrega cambiaron: refrescar la fila (y el chat si es la abierta),
    // sin spinner y sin saltar el scroll. Debounced para coalescer rafagas.
    clearTimeout(listRefetchDebounce);
    listRefetchDebounce = setTimeout(() => loadContacts(true), 300);
    if (selected.value && e.contact_id === selected.value.id) {
      clearTimeout(chatRefetchDebounce);
      chatRefetchDebounce = setTimeout(() => refreshOpenChat(false), 300);
    }
  });
}

// scroll=true baja al final (mensaje nuevo). En refrescos de estado/entrega va false para
// no mover el scroll mientras el operador lee. Sincroniza tambien estado + asignacion.
async function refreshOpenChat(scroll = false) {
  if (! selected.value) return;
  const res = await api.conversation(selected.value.id);
  if (res.status === 'ok') {
    messages.value          = res.data.messages;
    windowOpen.value        = res.data.window_open;
    selected.value          = { ...selected.value, ...res.data.contact };
    currentAssignment.value = res.data.contact.assigned_to ?? null;
    if (scroll) {
      await nextTick();
      scrollToBottom();
    }
  }
}

async function loadUsers() {
  const res = await api.assignableUsers();
  if (res.status === 'ok') users.value = res.data ?? [];
}

async function loadContacts(silent = false) {
  if (! silent) loadingContacts.value = true;
  const res = await api.conversations();
  if (res.status === 'ok') contacts.value = res.data;
  if (! silent) loadingContacts.value = false;
}

async function selectContact(contact) {
  selected.value    = contact;
  loadingChat.value = true;
  messages.value    = [];
  currentAssignment.value = contact.assigned_to ?? null;
  assignUserId.value = null;
  const res = await api.conversation(contact.id);
  if (res.status === 'ok') {
    messages.value   = res.data.messages;
    windowOpen.value = res.data.window_open;
    selected.value   = { ...contact, ...res.data.contact };
  }
  loadingChat.value = false;
  await nextTick();
  scrollToBottom();
}

async function claimConversation() {
  claiming.value = true;
  const res = await api.claimConversation(selected.value.id);
  if (res.status === 'ok') {
    currentAssignment.value = res.data.assigned_to;
    toast.add({ severity: 'success', summary: 'Asignado', detail: 'Conversación tomada', life: 2500 });
  }
  claiming.value = false;
}

async function doAssign() {
  if (!assignUserId.value) return;

  const reasignando = !!currentAssignment.value;
  assigning.value = true;
  const res = await api.assignConversation(selected.value.id, assignUserId.value);
  assigning.value = false;

  if (res.status !== 'ok') {
    // Reasignar al mismo agente se rechaza: evita movimientos que no movieron nada.
    toast.add({ severity: 'warn', summary: 'No se pudo asignar', detail: res.message, life: 4000 });
    return;
  }

  currentAssignment.value = res.data.assigned_to;
  assignUserId.value = null;
  toast.add({
    severity : 'success',
    summary  : reasignando ? 'Reasignada' : 'Asignada',
    detail   : `Conversación asignada a ${res.data.assigned_to.name}`,
    life     : 2500,
  });
}

// Soltar la conversación (cambio de turno sin relevo inmediato). No borra el historial:
// queda registrada como un movimiento más.
async function doRelease() {
  releasing.value = true;
  const res = await api.releaseConversation(selected.value.id);
  releasing.value = false;

  if (res.status !== 'ok') {
    toast.add({ severity: 'error', summary: 'No se pudo soltar', detail: res.message, life: 4000 });
    return;
  }

  currentAssignment.value = null;
  toast.add({ severity: 'success', summary: 'Sin asignar', detail: 'La conversación quedó libre.', life: 2500 });
}

async function openHistory() {
  loadingHistory.value = true;
  const res = await api.conversationHistory(selected.value.id);
  loadingHistory.value = false;

  if (res.status !== 'ok') {
    toast.add({ severity: 'error', summary: 'No se pudo cargar el historial', detail: res.message, life: 4000 });
    return;
  }

  history.value = res.data ?? [];
  historyDialog.value = true;
}

/**
 * El texto ya no vive aqui: lo manda ConversationComposer. La cajita se limpia SOLO si el
 * envio salio bien, para que un fallo de red no le borre al operador lo que escribio.
 */
async function sendMessage(texto) {
  if (!texto || sending.value) return;
  sending.value = true;
  const res = await api.sendMessage(selected.value.id, texto);
  if (res.status === 'ok') {
    messages.value.push(res.data);
    composer.value?.limpiar();
    await nextTick();
    scrollToBottom();
  } else {
    toast.add({ severity: 'error', summary: 'Error', detail: res.message || 'No se pudo enviar', life: 4000 });
  }
  sending.value = false;
}

async function loadQuickReplies() {
  const res = await api.quickReplies();
  if (res.status === 'ok') quickReplies.value = res.data;
}

async function saveQR() {
  if (!newQR.value.title || !newQR.value.body) return;
  savingQR.value = true;
  const res = await api.createQuickReply(newQR.value);
  if (res.status === 'ok') {
    quickReplies.value.push(res.data);
    newQR.value = { title: '', body: '' };
    showNewQR.value = false;
  }
  savingQR.value = false;
}

async function deleteQR(id) {
  await api.deleteQuickReply(id);
  quickReplies.value = quickReplies.value.filter(q => q.id !== id);
}

function scrollToBottom() {
  if (messagesContainer.value) messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
}

function formatTime(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
}
function formatDate(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleDateString('es-MX', { day: '2-digit', month: 'short' });
}
</script>

<style scoped>
.conv-page {
  display: grid;
  grid-template-columns: 260px 1fr 220px;
  /* minmax(0, 1fr) + min-height:0 en las columnas: por defecto una celda de grid no se encoge
     por debajo de su contenido, así que con muchas conversaciones la fila crecía más alto que
     .conv-page, la columna del chat se estiraba con ella y la barra de responder quedaba fuera
     de vista (recortada por el overflow:hidden). Se veía como "desaparece la barra". */
  grid-template-rows: minmax(0, 1fr);
  gap: 0;
  /* dvh (no vh): en móvil la barra del navegador hace que 100vh sea MAYOR que lo visible,
     y la barra de responder queda debajo del borde de la pantalla. */
  height: calc(100dvh - 56px - 48px); /* topbar + padding del content */
  border: 1px solid var(--p-content-border-color);
  border-radius: 12px;
  overflow: hidden;
  background: var(--p-content-background);
}

/* Sidebar */
.conv-sidebar  { display: flex; flex-direction: column; min-height: 0; border-right: 1px solid var(--p-content-border-color); }
.sidebar-header {
  flex-shrink: 0;
  padding: 14px 16px;
  border-bottom: 1px solid var(--p-content-border-color);
  display: flex; align-items: center; justify-content: space-between;
}
.sidebar-title  { font-weight: 700; font-size: .95rem; }
.sidebar-count  { font-size: .75rem; color: var(--p-text-muted-color); }
.sidebar-list   { flex: 1; min-height: 0; overflow-y: auto; }
.sidebar-empty  { padding: 24px 16px; text-align: center; font-size: .82rem; color: var(--p-text-muted-color); }

/* Chat */
.conv-chat  { display: flex; flex-direction: column; min-height: 0; overflow: hidden; }

.chat-empty {
  flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
  color: var(--p-text-muted-color); gap: 10px;
}
.chat-empty-icon { font-size: 3rem; }

/* flex-shrink:0 en cabecera y barra de escritura: sin esto, cuando el chat no cabe a lo alto
   (muchas respuestas rápidas, pantalla corta) el navegador las encoge para dar espacio a los
   mensajes, y como .conv-chat tiene overflow:hidden, la barra de responder desaparecía. */
.chat-header {
  flex-shrink: 0;
  padding: 12px 20px;
  border-bottom: 1px solid var(--p-content-border-color);
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  background: var(--p-content-background);
}
.chat-name   { display: block; font-weight: 700; font-size: .95rem; }
.chat-phone  { display: block; font-size: .75rem; color: var(--p-text-muted-color); }
.chat-badges { display: flex; gap: 6px; flex-wrap: wrap; }

.chat-messages { flex: 1; min-height: 0; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 8px; background: var(--p-surface-50); }
.chat-loading  { text-align: center; color: var(--p-text-muted-color); font-size: .82rem; }

.msg-row     { display: flex; }
.msg-row--out{ justify-content: flex-end; }
.msg-row--in { justify-content: flex-start; }

.msg-bubble  { max-width: 70%; padding: 8px 12px; border-radius: 16px; font-size: .82rem; }
.msg-bubble--out { background: var(--p-primary-500); color: #fff; border-bottom-right-radius: 4px; }
.msg-bubble--in  { background: #fff; color: var(--p-text-color); border: 1px solid var(--p-surface-200); border-bottom-left-radius: 4px; }

.msg-text { margin: 0; white-space: pre-wrap; word-break: break-word; }
.msg-meta  { display: flex; align-items: center; justify-content: flex-end; gap: 4px; margin-top: 4px; font-size: .68rem; opacity: .7; }
.msg-status{ font-size: .7rem; }

/* Panel info */
.conv-info  { display: flex; flex-direction: column; min-height: 0; border-left: 1px solid var(--p-content-border-color); overflow-y: auto; }
.info-section { padding: 16px; border-bottom: 1px solid var(--p-content-border-color); }
.info-section--grow { flex: 1; }
.info-title  { font-size: .8rem; font-weight: 700; color: var(--p-text-muted-color); text-transform: uppercase; letter-spacing: .04em; margin: 0 0 10px; }
.info-title-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.info-title-row .info-title { margin: 0; }
.info-rows   { display: flex; flex-direction: column; gap: 8px; }
.info-row    { display: flex; flex-direction: column; gap: 2px; }
.info-lbl    { font-size: .72rem; color: var(--p-text-muted-color); }
.info-val    { font-size: .82rem; font-weight: 600; }
.info-val--warn { color: var(--p-orange-600); }

.qr-list { display: flex; flex-direction: column; gap: 6px; }
.qr-item { display: flex; align-items: flex-start; justify-content: space-between; gap: 4px; padding: 8px; background: var(--p-surface-50); border-radius: 8px; }
.qr-item-title { font-size: .78rem; font-weight: 600; margin: 0 0 2px; }
.qr-item-text  { font-size: .72rem; color: var(--p-text-muted-color); margin: 0; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }

/* Dialog form */
.form-grid  { display: flex; flex-direction: column; gap: 14px; padding-top: 8px; }
.form-field { display: flex; flex-direction: column; gap: 4px; }
.form-field label { font-size: .85rem; font-weight: 600; }

/* Assignment */
.assign-current { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; }
.assign-icon    { font-size: .85rem; color: var(--p-primary-500); }
.assign-name    { font-size: .82rem; font-weight: 600; }
.assign-empty   { font-size: .78rem; color: var(--p-text-muted-color); margin-bottom: 8px; }
.assign-btn     { width: 100%; margin-bottom: 6px; }
.assign-select  { margin-bottom: 6px; }

/* Botón "atrás" del chat: solo en móvil (master-detail). */
.chat-back { display: none; }

/* ── Responsive móvil: una sola columna, master-detail ────────────
   Sin selección se ve la lista; al elegir un chat se ve la conversación
   (con botón atrás). El panel de info/agentes se oculta en móvil. */
@media (max-width: 768px) {
  .conv-page { grid-template-columns: 1fr; height: calc(100dvh - 56px - 32px); }
  .conv-chat, .conv-info { display: none; }
  .conv-page--detail .conv-sidebar { display: none; }
  .conv-page--detail .conv-chat    { display: flex; }
  .chat-back { display: inline-flex; }
}

/* ── Historial de asignaciones (P2) ─────────────────────────────────────── */
.history-contact { margin: 0 0 12px; font-size: .85rem; color: var(--p-text-muted-color); }
.history-empty   { margin: 0; font-size: .85rem; color: var(--p-text-muted-color); }

.history-list { list-style: none; margin: 0; padding: 0; }

.history-item {
  display: flex;
  gap: 10px;
  padding: 10px 0;
  border-bottom: 1px solid var(--p-surface-200);
}
.history-item:last-child { border-bottom: none; }

.history-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  margin-top: 5px;
  flex-shrink: 0;
  background: var(--p-surface-400);
}
.dot--auto     { background: var(--p-surface-400); }
.dot--manual   { background: var(--p-primary-500); }
.dot--claim    { background: var(--p-primary-400); }
.dot--reassign { background: var(--p-orange-500, #f97316); }
.dot--release  { background: var(--p-red-500, #ef4444); }

.history-body  { min-width: 0; }
.history-head  { font-size: .88rem; }
.history-agent { color: var(--p-primary-600); font-weight: 600; margin-left: 4px; }
.history-meta  { font-size: .78rem; color: var(--p-text-muted-color); margin-top: 2px; }

</style>
