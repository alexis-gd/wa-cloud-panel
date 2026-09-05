<template>
    <div class="chat-input-area">
        <div v-if="optedOut" class="chat-notice chat-notice--danger">
            Este contacto está dado de baja - no se le puede enviar mensajes.
        </div>
        <template v-else>
            <div v-if="!windowOpen" class="chat-notice">
                Ventana de 24h cerrada. Envía una plantilla para reabrir la conversación.
            </div>
            <!-- Respuestas rápidas (solo cuando ventana abierta) -->
            <div v-if="windowOpen && quickReplies.length" class="quick-replies">
                <button v-for="qr in quickReplies" :key="qr.id" @click="usar(qr)" class="qr-chip">
                    {{ qr.title }}
                </button>
            </div>
            <div class="input-row">
                <Textarea
                    v-model="texto"
                    :disabled="!windowOpen"
                    placeholder="Escribe tu mensaje..."
                    :autoResize="true"
                    rows="1"
                    class="msg-input"
                    @keydown.enter.exact.prevent="enviar"
                />
                <Button
                    icon="pi pi-send"
                    :loading="sending"
                    :disabled="!texto.trim() || !windowOpen"
                    @click="enviar"
                />
            </div>
            <p v-if="windowOpen" class="input-hint">Enter para enviar · Shift+Enter para nueva línea</p>
        </template>
    </div>
</template>

<script setup>
/**
 * La cajita de escribir, en su propio componente.
 *
 * Por qué existe: el texto que se está tecleando vivía en ConversationsView, junto con la
 * lista lateral. En Vue, cambiar una variable reactiva vuelve a ejecutar el render de TODO
 * el componente, así que cada tecla redibujaba las conversaciones de la lista - con 938
 * filas, el operador veía las letras aparecer tarde. Con el texto aquí dentro, teclear solo
 * redibuja esta cajita.
 *
 * El padre sigue mandando el mensaje (es quien tiene el contacto y el API); este componente
 * solo avisa qué escribió. Y no se limpia solo al enviar: el padre llama a `limpiar()` si el
 * envío salió bien, para que un fallo de red no le borre al operador lo que ya había escrito.
 */
import { ref } from 'vue';
import Textarea from 'primevue/textarea';
import Button   from 'primevue/button';

defineProps({
    windowOpen  : { type: Boolean, default: false },
    optedOut    : { type: Boolean, default: false },
    sending     : { type: Boolean, default: false },
    quickReplies: { type: Array,   default: () => [] },
});

const emit = defineEmits(['send']);

const texto = ref('');

function enviar() {
    const limpio = texto.value.trim();
    if (!limpio) return;
    emit('send', limpio);
}

function usar(qr) {
    texto.value = qr.body;
}

/** La llama el padre cuando el mensaje sí salió. */
function limpiar() {
    texto.value = '';
}

defineExpose({ limpiar });
</script>

<style scoped>
.chat-input-area {
  flex-shrink: 0;
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
</style>
