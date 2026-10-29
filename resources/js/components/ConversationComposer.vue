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
            <!-- El archivo elegido, antes de mandarlo. Se ve para que el operador confirme
                 que es el que queria: una vez enviado no hay forma de retirarlo. -->
            <div v-if="archivo" class="adjunto">
                <img v-if="vistaPrevia" :src="vistaPrevia" alt="" class="adjunto-img" />
                <i v-else :class="['adjunto-icono', 'pi', iconoArchivo]"></i>
                <div class="adjunto-datos">
                    <span class="adjunto-nombre">{{ archivo.name }}</span>
                    <span class="adjunto-peso">{{ pesoArchivo }}</span>
                </div>
                <Button
                    icon="pi pi-times"
                    text
                    size="small"
                    :disabled="sending"
                    v-tooltip.top="'Quitar'"
                    @click="quitarArchivo"
                />
            </div>

            <div class="input-row">
                <Button
                    icon="pi pi-paperclip"
                    text
                    :disabled="!windowOpen || sending"
                    v-tooltip.top="'Adjuntar un archivo'"
                    @click="elegirArchivo"
                />
                <Textarea
                    v-model="texto"
                    :disabled="!windowOpen"
                    :placeholder="placeholder"
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
                accept="image/jpeg,image/png,image/webp,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,video/mp4,video/3gpp,audio/mpeg,audio/mp4,audio/aac,audio/ogg,audio/amr"
                class="selector-oculto"
                @change="alElegir"
            />

            <p v-if="windowOpen" class="input-hint">
                <template v-if="esAudio">Las notas de voz no llevan texto · Enter para enviar</template>
                <template v-else-if="archivo">El texto va junto al archivo · Enter para enviar</template>
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

const emit = defineEmits(['send', 'send-file']);

const texto       = ref('');
const archivo     = ref(null);
const vistaPrevia = ref(null);
const selector    = ref(null);

const ICONOS = {
    video: 'pi-video',
    audio: 'pi-volume-up',
};

const tipoArchivo = computed(() => (archivo.value?.type ?? '').split('/')[0]);
const esAudio     = computed(() => tipoArchivo.value === 'audio');
const iconoArchivo = computed(() => ICONOS[tipoArchivo.value] ?? 'pi-file');
const pesoArchivo  = computed(() => (archivo.value ? formatPeso(archivo.value.size) : ''));
const puedeEnviar  = computed(() => !!(archivo.value || texto.value.trim()));

const placeholder = computed(() => {
    if (esAudio.value)    return 'Las notas de voz no llevan texto...';
    if (archivo.value)    return 'Texto para acompañar el archivo (opcional)...';

    return 'Escribe tu mensaje...';
});

function elegirArchivo() {
    selector.value?.click();
}

function alElegir(e) {
    const elegido = e.target.files?.[0];

    // Se limpia el input o elegir dos veces el mismo archivo seguidas no dispara el evento.
    e.target.value = '';

    if (! elegido) return;

    soltarUrl();
    archivo.value = elegido;

    // Solo las imagenes se previsualizan. Un video o un PDF no tienen nada que ensenar aqui
    // sin descargarlos, y ya pesan: se muestran con su icono y su nombre.
    if (elegido.type.startsWith('image/')) {
        vistaPrevia.value = URL.createObjectURL(elegido);
    }
}

function quitarArchivo() {
    soltarUrl();
    archivo.value = null;
}

/** Sin esto el navegador se queda la imagen en memoria mientras viva la pestana. */
function soltarUrl() {
    if (vistaPrevia.value) URL.revokeObjectURL(vistaPrevia.value);
    vistaPrevia.value = null;
}

function enviar() {
    const limpio = texto.value.trim();

    // Con archivo, el texto es opcional. Sin archivo, un mensaje vacio no se manda.
    if (archivo.value) {
        emit('send-file', { file: archivo.value, caption: esAudio.value ? '' : limpio });

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
    quitarArchivo();
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
.adjunto-icono  { font-size: 1.6rem; width: 44px; text-align: center; color: var(--p-text-muted-color); flex-shrink: 0; }
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
