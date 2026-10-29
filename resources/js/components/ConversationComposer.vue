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
            <!-- La foto elegida, antes de mandarla. Se ve para que el operador confirme que
                 es la que queria: una vez enviada no hay forma de retirarla. -->
            <div v-if="foto" class="adjunto">
                <img :src="fotoUrl" alt="" class="adjunto-img" />
                <div class="adjunto-datos">
                    <span class="adjunto-nombre">{{ foto.name }}</span>
                    <span class="adjunto-peso">{{ pesoFoto }}</span>
                </div>
                <Button
                    icon="pi pi-times"
                    text
                    size="small"
                    :disabled="sending"
                    v-tooltip.top="'Quitar'"
                    @click="quitarFoto"
                />
            </div>

            <div class="input-row">
                <Button
                    icon="pi pi-image"
                    text
                    :disabled="!windowOpen || sending"
                    v-tooltip.top="'Enviar una foto'"
                    @click="elegirFoto"
                />
                <Textarea
                    v-model="texto"
                    :disabled="!windowOpen"
                    :placeholder="foto ? 'Texto de la foto (opcional)...' : 'Escribe tu mensaje...'"
                    :autoResize="true"
                    rows="1"
                    class="msg-input"
                    @keydown.enter.exact.prevent="enviar"
                />
                <Button
                    icon="pi pi-send"
                    :loading="sending"
                    :disabled="!puedeEnviar"
                    @click="enviar"
                />
            </div>

            <input
                ref="selector"
                type="file"
                accept="image/jpeg,image/png,image/webp"
                class="selector-oculto"
                @change="alElegir"
            />

            <p v-if="windowOpen" class="input-hint">
                <template v-if="foto">El texto va como pie de la foto · Enter para enviar</template>
                <template v-else>Enter para enviar · Shift+Enter para nueva línea</template>
            </p>
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
import { ref, computed, onBeforeUnmount } from 'vue';
import Textarea from 'primevue/textarea';
import Button   from 'primevue/button';
import { formatPeso } from '../media.js';

defineProps({
    windowOpen  : { type: Boolean, default: false },
    optedOut    : { type: Boolean, default: false },
    sending     : { type: Boolean, default: false },
    quickReplies: { type: Array,   default: () => [] },
});

const emit = defineEmits(['send', 'send-image']);

const texto    = ref('');
const foto     = ref(null);
const fotoUrl  = ref(null);
const selector = ref(null);

const pesoFoto    = computed(() => (foto.value ? formatPeso(foto.value.size) : ''));
const puedeEnviar = computed(() => !!(foto.value || texto.value.trim()));

function elegirFoto() {
    selector.value?.click();
}

function alElegir(e) {
    const elegida = e.target.files?.[0];

    // Se limpia el input o elegir dos veces la misma foto seguidas no dispara el evento.
    e.target.value = '';

    if (! elegida) return;

    soltarUrl();
    foto.value    = elegida;
    fotoUrl.value = URL.createObjectURL(elegida);
}

function quitarFoto() {
    soltarUrl();
    foto.value = null;
}

/** Sin esto el navegador se queda la foto en memoria mientras viva la pestana. */
function soltarUrl() {
    if (fotoUrl.value) URL.revokeObjectURL(fotoUrl.value);
    fotoUrl.value = null;
}

function enviar() {
    const limpio = texto.value.trim();

    // Con foto, el texto es el pie y puede ir vacio. Sin foto, un mensaje vacio no se manda.
    if (foto.value) {
        emit('send-image', { file: foto.value, caption: limpio });

        return;
    }

    if (! limpio) return;

    emit('send', limpio);
}

function usar(qr) {
    texto.value = qr.body;
}

/** La llama el padre cuando el mensaje si salio. */
function limpiar() {
    texto.value = '';
    quitarFoto();
}

onBeforeUnmount(soltarUrl);

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

.selector-oculto { display: none; }

.adjunto {
  display: flex; align-items: center; gap: 10px;
  padding: 8px; margin-bottom: 8px;
  border: 1px solid var(--p-content-border-color); border-radius: 8px;
  background: var(--p-surface-50);
}
.adjunto-img    { width: 44px; height: 44px; object-fit: cover; border-radius: 6px; flex-shrink: 0; }
.adjunto-datos  { display: flex; flex-direction: column; min-width: 0; flex: 1; }
.adjunto-nombre {
  font-size: .78rem; font-weight: 600;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.adjunto-peso   { font-size: .68rem; color: var(--p-text-muted-color); }

.input-row  { display: flex; gap: 8px; align-items: flex-end; }
.msg-input  { flex: 1; }
.input-hint { font-size: .7rem; color: var(--p-text-muted-color); margin: 4px 0 0; }
</style>
