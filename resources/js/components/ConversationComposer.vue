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

            <!-- Grabando. Ocupa el lugar de la fila de escribir, no se suma a ella: mientras
                 se graba no hay nada mas que hacer, y dejar la cajita ahi invitaria a teclear
                 un texto que la nota de voz no puede llevar. -->
            <div v-if="grabando" class="rec-row">
                <Button
                    icon="pi pi-trash"
                    text
                    severity="danger"
                    :disabled="sending"
                    v-tooltip.top="'Descartar la grabación'"
                    @click="descartar"
                />
                <div class="rec-centro">
                    <span class="rec-punto" :class="{ 'rec-punto--quieto': pausado || topeAlcanzado }"></span>
                    <span class="rec-tiempo">{{ tiempo }}</span>
                    <div class="rec-onda">
                        <span
                            v-for="(nivel, i) in niveles"
                            :key="i"
                            class="rec-barra"
                            :style="{ height: nivel + '%' }"
                        ></span>
                    </div>
                    <Button
                        v-if="!topeAlcanzado"
                        :icon="pausado ? 'pi pi-play' : 'pi pi-pause'"
                        :label="pausado ? 'Seguir' : 'Pausar'"
                        text
                        size="small"
                        :disabled="sending"
                        @click="alternarPausa"
                    />
                </div>
                <Button
                    icon="pi pi-send"
                    :loading="sending"
                    v-tooltip.top="'Enviar la nota de voz'"
                    @click="enviarVoz"
                />
            </div>

            <div v-else class="input-row">
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
                <!-- Microfono y enviar se turnan el mismo lugar, como en WhatsApp: en cuanto
                     hay algo que mandar, el microfono desaparece. Dos botones juntos obligarian
                     a leer cual es cual cada vez; asi el de la derecha siempre es "manda lo que
                     tengo delante". -->
                <Button
                    v-if="mostrarMicro"
                    icon="pi pi-microphone"
                    :disabled="!windowOpen || sending"
                    v-tooltip.top="'Grabar una nota de voz'"
                    @click="grabar"
                />
                <Button
                    v-else
                    icon="pi pi-send"
                    :loading="sending"
                    :disabled="!puedeEnviar"
                    @click="enviar"
                />
            </div>

            <input
                ref="selector"
                type="file"
                accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.mp4,.3gp,.mp3,.m4a,.aac,.ogg,.oga,.opus,.amr"
                class="selector-oculto"
                @change="alElegir"
            />

            <p v-if="microError" class="input-hint input-hint--error">{{ microError }}</p>

            <p v-else-if="windowOpen" class="input-hint">
                <template v-if="topeAlcanzado">Llegaste al máximo de 2 minutos. Envíala o descártala.</template>
                <template v-else-if="grabando">Grabando · Se corta sola a los 2 minutos</template>
                <template v-else-if="esAudio">Las notas de voz no llevan texto · Enter para enviar</template>
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

const props = defineProps({
    windowOpen  : { type: Boolean, default: false },
    optedOut    : { type: Boolean, default: false },
    sending     : { type: Boolean, default: false },
    quickReplies: { type: Array,   default: () => [] },
    // Si el servidor puede convertir la grabacion a lo que acepta WhatsApp. Lo decide el
    // servidor (tiene ffmpeg o no), no una preferencia: sin esto el microfono prometeria algo
    // que falla al final, cuando el operador ya grabo.
    voiceNotes  : { type: Boolean, default: false },
});

const emit = defineEmits(['send', 'send-file', 'send-voice']);

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

// -- Notas de voz ----------------------------------------------------------------
//
// El navegador graba en el formato que sabe (WebM casi siempre, MP4 en Safari) y no deja
// elegir otro, asi que la conversion la hace el servidor. Aqui solo se graba, se mide y se
// manda.
//
// Lo delicado no es grabar: es soltar el microfono. Mientras el MediaStream viva, el navegador
// deja el indicador de grabacion encendido y el sistema marca la pestana como escuchando. Cada
// salida de esta seccion - enviar, descartar, error, cerrar la conversacion - termina en
// `soltarMicrofono()`.

/** Dos minutos. Acota el peso contra el tope de 16 MB y evita la grabacion olvidada. */
const MAX_SEGUNDOS = 120;

/** Cuantas barras tiene la onda. */
const BARRAS = 18;

const grabando      = ref(false);
const pausado       = ref(false);
const topeAlcanzado = ref(false);
const microError    = ref(null);
const segundos      = ref(0);
const niveles       = ref(Array(BARRAS).fill(6));

let grabadora  = null;
let pista      = null;
let audio      = null;
let analizador = null;
let trozos     = [];
let reloj      = null;
let medidor    = null;

const tiempo = computed(() => {
    const m = Math.floor(segundos.value / 60);
    const s = String(segundos.value % 60).padStart(2, '0');

    return m + ':' + s;
});

/**
 * El microfono solo aparece cuando no hay nada mas que mandar.
 *
 * Tambien depende del navegador: sin `MediaRecorder` (navegador viejo, o una pagina servida sin
 * HTTPS, donde el navegador esconde el microfono) el boton no se ofrece.
 */
const puedeGrabar = typeof window !== 'undefined'
    && typeof window.MediaRecorder !== 'undefined'
    && !! navigator.mediaDevices?.getUserMedia;

const mostrarMicro = computed(() =>
    puedeGrabar && props.voiceNotes && ! archivo.value && ! texto.value.trim());

/**
 * El formato en el que se graba.
 *
 * Se pide Opus a proposito: es lo que lleva dentro una nota de voz de WhatsApp, asi que al
 * servidor le basta cambiar el envase y no recodificar. Si el navegador no lo ofrece se le deja
 * elegir, y el servidor recodifica.
 */
function formatoDeGrabacion() {
    const preferidos = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'];

    return preferidos.find(f => MediaRecorder.isTypeSupported?.(f)) ?? '';
}

async function grabar() {
    if (grabando.value || props.sending) return;

    microError.value = null;

    let flujo;

    try {
        flujo = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (e) {
        microError.value = e?.name === 'NotAllowedError'
            ? 'No se pudo usar el micrófono: el navegador no dio permiso. Permítelo en el candado de la barra de direcciones.'
            : 'No se pudo usar el micrófono. Revisa que haya uno conectado.';

        return;
    }

    pista  = flujo;
    trozos = [];

    const mimeType = formatoDeGrabacion();

    grabadora = new MediaRecorder(flujo, mimeType ? { mimeType } : undefined);
    grabadora.ondataavailable = (e) => { if (e.data?.size) trozos.push(e.data); };
    grabadora.start();

    grabando.value      = true;
    pausado.value       = false;
    topeAlcanzado.value = false;
    segundos.value      = 0;
    niveles.value       = Array(BARRAS).fill(6);

    arrancarReloj();
    arrancarMedidor(flujo);
}

function arrancarReloj() {
    reloj = setInterval(() => {
        if (pausado.value) return;

        segundos.value += 1;

        if (segundos.value >= MAX_SEGUNDOS) alcanzarTope();
    }, 1000);
}

/**
 * Las barras de la onda.
 *
 * Se refrescan 10 veces por segundo y no en cada cuadro: una variable reactiva que cambia 60
 * veces por segundo vuelve a dibujar el componente otras tantas, y el ojo no nota la diferencia
 * en una onda de 18 barras.
 *
 * Es el nivel REAL del microfono, no una animacion: asi el operador ve de inmediato si no esta
 * captando nada, en vez de descubrirlo cuando el contacto no oye la nota.
 */
function arrancarMedidor(flujo) {
    const Contexto = window.AudioContext ?? window.webkitAudioContext;

    if (! Contexto) return;

    audio      = new Contexto();
    analizador = audio.createAnalyser();
    analizador.fftSize = 256;

    audio.createMediaStreamSource(flujo).connect(analizador);

    const datos = new Uint8Array(analizador.frequencyBinCount);

    medidor = setInterval(() => {
        if (pausado.value) return;

        analizador.getByteTimeDomainData(datos);

        // 128 es el silencio; la distancia a 128 es la amplitud.
        let pico = 0;

        for (const v of datos) pico = Math.max(pico, Math.abs(v - 128));

        const alto = Math.min(100, Math.max(6, (pico / 128) * 140));

        niveles.value = [...niveles.value.slice(1), Math.round(alto)];
    }, 100);
}

function alternarPausa() {
    if (! grabadora || topeAlcanzado.value) return;

    if (pausado.value) {
        grabadora.resume();
        pausado.value = false;

        return;
    }

    grabadora.pause();
    pausado.value = true;
}

/** Se acabo el tiempo: deja de capturar, pero no descarta ni manda - eso lo decide el operador. */
function alcanzarTope() {
    topeAlcanzado.value = true;

    pararRelojes();

    if (grabadora && grabadora.state !== 'inactive') grabadora.stop();
}

function descartar() {
    cerrarGrabacion();
}

/**
 * Manda lo grabado.
 *
 * `stop()` no devuelve los bytes: avisa por `onstop` cuando termino de vaciarlos. Por eso el
 * envio va dentro de ese aviso y no despues de la llamada - leer `trozos` antes daria una nota
 * cortada.
 */
function enviarVoz() {
    if (! grabadora || props.sending) return;

    const tipo = grabadora.mimeType || 'audio/webm';

    const mandar = () => {
        const blob = new Blob(trozos, { type: tipo });

        cerrarGrabacion();

        if (blob.size > 0) emit('send-voice', blob);
    };

    if (grabadora.state === 'inactive') {
        mandar();

        return;
    }

    grabadora.onstop = mandar;
    grabadora.stop();
}

function pararRelojes() {
    clearInterval(reloj);
    clearInterval(medidor);
    reloj = medidor = null;
}

/** Suelta todo y vuelve a la cajita de escribir. */
function cerrarGrabacion() {
    pararRelojes();

    if (grabadora && grabadora.state !== 'inactive') {
        grabadora.onstop = null;
        grabadora.stop();
    }

    soltarMicrofono();

    grabadora = null;
    trozos    = [];

    grabando.value      = false;
    pausado.value       = false;
    topeAlcanzado.value = false;
    segundos.value      = 0;
}

/**
 * Apaga el microfono de verdad.
 *
 * Sin detener cada pista, el navegador deja el punto rojo de "esta grabando" encendido aunque
 * el panel ya no grabe nada. Para el operador eso es el panel escuchandolo a sus espaldas.
 */
function soltarMicrofono() {
    pista?.getTracks().forEach(t => t.stop());
    pista = null;

    audio?.close().catch(() => {});
    audio = analizador = null;
}

/** La llama el padre cuando el mensaje si salio. */
function limpiar() {
    texto.value = '';
    quitarArchivo();
}

onBeforeUnmount(() => {
    soltarUrl();
    cerrarGrabacion();
});

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
.input-hint--error { color: var(--p-red-500); }

/* Grabando. Misma altura y mismos extremos que la fila de escribir para que el cambio no
   mueva el chat de arriba. */
.rec-row {
  display: flex; gap: 8px; align-items: center;
}
.rec-centro {
  display: flex; align-items: center; gap: 10px;
  flex: 1; min-width: 0;
  padding: 6px 10px 6px 14px;
  border-radius: 20px;
  background: var(--p-surface-100);
}
.rec-punto {
  width: 9px; height: 9px; border-radius: 50%;
  background: var(--p-red-500); flex-shrink: 0;
  animation: rec-latido 1.2s ease-in-out infinite;
}
.rec-punto--quieto { animation: none; opacity: .4; }
@keyframes rec-latido { 50% { opacity: .25; } }

.rec-tiempo {
  font-size: .82rem; font-variant-numeric: tabular-nums;
  color: var(--p-text-color); flex-shrink: 0;
}
.rec-onda {
  display: flex; align-items: center; gap: 2px;
  flex: 1; min-width: 0; height: 22px; overflow: hidden;
}
.rec-barra {
  flex: 1; min-width: 2px; border-radius: 2px;
  background: var(--p-primary-400);
  transition: height .1s linear;
}

@media (max-width: 640px) {
  /* En movil la onda se come el tiempo y el boton de pausar. Primero lo que hace falta. */
  .rec-onda { display: none; }
}
</style>
