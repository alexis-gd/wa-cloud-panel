<template>
  <div class="conv-page">

    <!-- Panel izquierdo: lista de contactos -->
    <div class="conv-sidebar">
      <div class="sidebar-header">
        <span class="sidebar-title">Conversaciones</span>
        <span class="sidebar-count">{{ contacts.length }} contactos</span>
      </div>

      <div class="sidebar-list">
        <div v-if="loadingContacts" class="sidebar-empty">Cargando...</div>
        <div v-else-if="contacts.length === 0" class="sidebar-empty">Sin conversaciones aún</div>

        <div v-for="c in contacts" :key="c.id"
          @click="selectContact(c)"
          :class="['sidebar-item',
                   selected?.id === c.id ? 'sidebar-item--active' : '',
                   isMyConversation(c) ? 'sidebar-item--mine' : '']">
          <div class="item-row">
            <span class="item-name">{{ c.name || c.phone }}</span>
            <span class="item-time">{{ formatTime(c.last_message_at) }}</span>
          </div>
          <div class="item-row">
            <span class="item-preview">{{ c.last_message }}</span>
            <Tag v-if="!c.assigned_to"              value="Sin asignar" severity="warn"      class="item-tag" />
            <Tag v-else-if="!c.window_open"          value="Cerrada"    severity="secondary" class="item-tag" />
            <Tag v-else-if="c.snoozed_until"         value="Snooze"     severity="warn"      class="item-tag" />
            <Tag v-else-if="c.status==='opted_out'"  value="Baja"       severity="danger"    class="item-tag" />
            <Tag v-else                              value="Activa"      severity="success"   class="item-tag" />
          </div>
        </div>
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
          <div>
            <span class="chat-name">{{ selected.name || selected.phone }}</span>
            <span class="chat-phone">{{ selected.phone }}</span>
          </div>
          <div class="chat-badges">
            <Tag v-if="!windowOpen"              value="Ventana cerrada — el cliente debe responder primero" severity="warn" />
            <Tag v-else-if="selected.snoozed_until" :value="`Snooze hasta ${formatDate(selected.snoozed_until)}`" severity="warn" />
            <Tag v-else-if="selected.status==='opted_out'" value="Opt-out permanente" severity="danger" />
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

        <!-- Input -->
        <div class="chat-input-area">
          <div v-if="selected.status==='opted_out'" class="chat-notice chat-notice--danger">
            Este contacto tiene opt-out permanente — no se le puede enviar mensajes.
          </div>
          <template v-else>
            <div v-if="!windowOpen" class="chat-notice">
              Ventana de 24h cerrada. Envía una plantilla para reabrir la conversación.
            </div>
            <!-- Respuestas rápidas (solo cuando ventana abierta) -->
            <div v-if="windowOpen && quickReplies.length" class="quick-replies">
              <button v-for="qr in quickReplies" :key="qr.id" @click="useQuickReply(qr)" class="qr-chip">
                {{ qr.title }}
              </button>
            </div>
            <div class="input-row">
              <Textarea v-model="newMessage" :disabled="!windowOpen" placeholder="Escribe tu mensaje..."
                :autoResize="true" rows="1" class="msg-input" @keydown.enter.exact.prevent="sendMessage" />
              <Button icon="pi pi-send" :loading="sending" :disabled="!newMessage.trim() || !windowOpen"
                @click="sendMessage" />
            </div>
            <p v-if="windowOpen" class="input-hint">Enter para enviar · Shift+Enter para nueva línea</p>
          </template>
        </div>
      </template>
    </div>

    <!-- Panel derecho: info + asignación + quick replies admin -->
    <div v-if="selected" class="conv-info">
      <div class="info-section">
        <p class="info-title">Info del contacto</p>
        <div class="info-rows">
          <div class="info-row"><span class="info-lbl">Nombre</span><span class="info-val">{{ selected.name || '—' }}</span></div>
          <div class="info-row"><span class="info-lbl">Teléfono</span><span class="info-val">{{ selected.phone }}</span></div>
          <div class="info-row"><span class="info-lbl">Estado</span><span class="info-val">{{ selected.status }}</span></div>
          <div v-if="selected.snoozed_until" class="info-row">
            <span class="info-lbl">Snooze</span>
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
          <Button label="Asignar" size="small" :loading="assigning" :disabled="!assignUserId" @click="doAssign" class="assign-btn" />
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
</template>

<script setup>
import { ref, computed, nextTick, onMounted } from 'vue';
import { useToast } from 'primevue/usetoast';
import { useAuth }  from '../auth.js';
import { api }      from '../api.js';
import Button    from 'primevue/button';
import Textarea  from 'primevue/textarea';
import InputText from 'primevue/inputtext';
import Select    from 'primevue/select';
import Tag       from 'primevue/tag';
import Dialog    from 'primevue/dialog';

const toast = useToast();
const { user: authState } = useAuth();
const isAdmin            = computed(() => authState.user?.role === 'admin');
const isAdminOrOperator  = computed(() => ['admin', 'operator'].includes(authState.user?.role));

function isMyConversation(contact) {
  return contact.assigned_to?.id === authState.user?.id;
}

const contacts     = ref([]);
const selected     = ref(null);
const messages     = ref([]);
const windowOpen   = ref(false);
const quickReplies = ref([]);
const newMessage   = ref('');
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
const claiming         = ref(false);

onMounted(async () => {
  const promises = [loadContacts(), loadQuickReplies()];
  if (isAdminOrOperator.value) promises.push(loadUsers());
  await Promise.all(promises);
});

async function loadUsers() {
  const res = await api.users();
  if (res.status === 'ok') users.value = res.data ?? [];
}

async function loadContacts() {
  loadingContacts.value = true;
  const res = await api.conversations();
  if (res.status === 'ok') contacts.value = res.data;
  loadingContacts.value = false;
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
  assigning.value = true;
  const res = await api.assignConversation(selected.value.id, assignUserId.value);
  if (res.status === 'ok') {
    currentAssignment.value = res.data.assigned_to;
    assignUserId.value = null;
    toast.add({ severity: 'success', summary: 'Asignado', detail: `Conversación asignada a ${res.data.assigned_to.name}`, life: 2500 });
  }
  assigning.value = false;
}

async function sendMessage() {
  if (!newMessage.value.trim() || sending.value) return;
  sending.value = true;
  const res = await api.sendMessage(selected.value.id, newMessage.value.trim());
  if (res.status === 'ok') {
    messages.value.push(res.data);
    newMessage.value = '';
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

function useQuickReply(qr) { newMessage.value = qr.body; }

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
  gap: 0;
  height: calc(100vh - 56px - 48px); /* topbar + padding content */
  border: 1px solid var(--p-content-border-color);
  border-radius: 12px;
  overflow: hidden;
  background: var(--p-content-background);
}

/* Sidebar */
.conv-sidebar  { display: flex; flex-direction: column; border-right: 1px solid var(--p-content-border-color); }
.sidebar-header {
  padding: 14px 16px;
  border-bottom: 1px solid var(--p-content-border-color);
  display: flex; align-items: center; justify-content: space-between;
}
.sidebar-title  { font-weight: 700; font-size: .95rem; }
.sidebar-count  { font-size: .75rem; color: var(--p-text-muted-color); }
.sidebar-list   { flex: 1; overflow-y: auto; }
.sidebar-empty  { padding: 24px 16px; text-align: center; font-size: .82rem; color: var(--p-text-muted-color); }

.sidebar-item {
  padding: 10px 14px;
  border-bottom: 1px solid var(--p-surface-100);
  cursor: pointer;
  transition: background .12s;
}
.sidebar-item:hover         { background: var(--p-surface-50); }
.sidebar-item--active       { background: var(--p-primary-50); border-left: 3px solid var(--p-primary-500); }
.sidebar-item--mine         { border-left: 3px solid var(--p-green-500); }

.item-row    { display: flex; align-items: center; justify-content: space-between; gap: 6px; margin-bottom: 3px; }
.item-name   { font-size: .85rem; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }
.item-time   { font-size: .7rem; color: var(--p-text-muted-color); flex-shrink: 0; }
.item-preview{ font-size: .75rem; color: var(--p-text-muted-color); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }
.item-tag    { flex-shrink: 0; font-size: .65rem !important; }

/* Chat */
.conv-chat  { display: flex; flex-direction: column; overflow: hidden; }

.chat-empty {
  flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
  color: var(--p-text-muted-color); gap: 10px;
}
.chat-empty-icon { font-size: 3rem; }

.chat-header {
  padding: 12px 20px;
  border-bottom: 1px solid var(--p-content-border-color);
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  background: var(--p-content-background);
}
.chat-name   { display: block; font-weight: 700; font-size: .95rem; }
.chat-phone  { display: block; font-size: .75rem; color: var(--p-text-muted-color); }
.chat-badges { display: flex; gap: 6px; flex-wrap: wrap; }

.chat-messages { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 8px; background: var(--p-surface-50); }
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

.chat-input-area {
  padding: 12px 16px;
  border-top: 1px solid var(--p-content-border-color);
  background: var(--p-content-background);
}
.chat-notice        { text-align: center; font-size: .82rem; color: var(--p-text-muted-color); padding: 6px 0; }
.chat-notice--danger{ color: var(--p-red-500); }

.quick-replies { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.qr-chip {
  font-size: .72rem; padding: 3px 10px; border-radius: 20px;
  background: var(--p-surface-100); border: 1px solid var(--p-surface-200);
  cursor: pointer; color: var(--p-text-color); transition: background .12s;
}
.qr-chip:hover { background: var(--p-primary-100); color: var(--p-primary-700); }

.input-row  { display: flex; gap: 8px; align-items: flex-end; }
.msg-input  { flex: 1; }
.input-hint { font-size: .7rem; color: var(--p-text-muted-color); margin: 4px 0 0; }

/* Panel info */
.conv-info  { display: flex; flex-direction: column; border-left: 1px solid var(--p-content-border-color); overflow-y: auto; }
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
</style>
