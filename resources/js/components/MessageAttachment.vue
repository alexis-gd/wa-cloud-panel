<template>
    <div class="att">
        <!-- Todavia en la cola de descarga. No es un error: se arregla solo. -->
        <div v-if="descargando" class="att-note">
            <i class="pi pi-spin pi-spinner"></i>
            <span>Descargando archivo...</span>
        </div>

        <!-- Se agotaron los intentos, el archivo paso del tope, o ya no esta en disco.
             El texto cubre las dos causas a proposito: "que lo reenvie" seria mal consejo si
             el archivo se rechazo por pesado, porque volveria a llegar igual de pesado. -->
        <div v-else-if="fallido" class="att-note att-note--error">
            <i class="pi pi-exclamation-triangle"></i>
            <span>No se pudo recibir este archivo. Puede ser muy pesado, o WhatsApp ya no lo
                tiene disponible. Pídele al contacto que lo mande más ligero o por otro medio.</span>
        </div>

        <!-- Foto: miniatura, click para verla grande. -->
        <button
            v-else-if="esImagen"
            ref="caja"
            type="button"
            class="att-img-btn"
            :disabled="! url"
            @click="$emit('open', { url, filename: nombreDescarga })"
        >
            <img v-if="url" :src="url" :alt="nombre" class="att-img" />
            <span v-else-if="error" class="att-note att-note--error">
                <i class="pi pi-exclamation-triangle"></i>{{ error }}
            </span>
            <span v-else class="att-img-skeleton"><i class="pi pi-spin pi-spinner"></i></span>
        </button>

        <!-- Video: NO se precarga. Uno de 16 MB bajandose solo al pasar el scroll es muy
             distinto de una foto de 200 KB, asi que primero se ve una caratula y el archivo
             se pide al darle play. -->
        <div v-else-if="esVideo" class="att-video">
            <video v-if="url" :src="url" controls preload="metadata" class="att-video-player"></video>
            <button v-else type="button" class="att-video-poster" :disabled="abriendo" @click="verVideo">
                <i :class="abriendo ? 'pi pi-spin pi-spinner' : 'pi pi-play-circle'"></i>
                <span>{{ abriendo ? 'Cargando video...' : 'Ver video' }}</span>
                <small v-if="meta">{{ meta }}</small>
            </button>
            <span v-if="error" class="att-note att-note--error">
                <i class="pi pi-exclamation-triangle"></i>{{ error }}
            </span>
        </div>

        <!-- Nota de voz: el reproductor del navegador basta. -->
        <div v-else-if="esAudio" ref="caja" class="att-audio">
            <audio v-if="url" :src="url" controls preload="metadata"></audio>
            <span v-else-if="error" class="att-note att-note--error">
                <i class="pi pi-exclamation-triangle"></i>{{ error }}
            </span>
            <span v-else class="att-note"><i class="pi pi-spin pi-spinner"></i>Cargando audio...</span>
        </div>

        <!-- Documento y video: ficha con el nombre y un boton para abrirlo. No se precarga,
             porque un PDF o un video pesan y casi nunca se abren todos. -->
        <div v-else class="att-file">
            <i :class="['att-file-icon', 'pi', icono]"></i>
            <span class="att-file-info">
                <span class="att-file-name">{{ nombre }}</span>
                <span class="att-file-meta">{{ meta }}</span>
            </span>
            <Button
                :icon="abriendo ? 'pi pi-spin pi-spinner' : (seAbre ? 'pi pi-external-link' : 'pi pi-download')"
                text
                size="small"
                :disabled="abriendo"
                v-tooltip.top="seAbre ? 'Abrir en otra pestaña' : 'Descargar archivo'"
                @click="abrirArchivo"
            />
        </div>
    </div>
</template>

<script setup>
/**
 * El archivo que trae un mensaje: foto, documento, nota de voz o video.
 *
 * Por que es un componente aparte: cada adjunto se ve distinto y decide por su cuenta cuando
 * pedir su archivo, y la vista de Conversaciones ya es grande.
 *
 * Como se pide y como se suelta el archivo vive en `useArchivoDeMensaje`: es lo unico que los
 * cuatro tipos comparten, y tiene la parte delicada (la sesion y la memoria).
 *
 * Cuando se pide cada uno:
 * - **Foto y nota de voz**: al entrar a la pantalla. Pesan poco y se ven en la burbuja.
 * - **Video**: al darle play. Uno de 16 MB bajandose solo al pasar el scroll es otra cosa.
 * - **Documento**: al darle al boton. Casi nunca se abren todos.
 */
import { computed, onMounted, ref } from 'vue';
import Button from 'primevue/button';
import { useArchivoDeMensaje } from '../composables/useArchivoDeMensaje.js';
import {
    etiquetaDeTipo,
    formatPeso,
    guardarComo,
    nombreDeDescarga,
    nombreVisible,
} from '../media.js';

const props = defineProps({
    message: { type: Object, required: true },
});

defineEmits(['open']);

const { url, error, inline, bajar, bajarCuandoSeVea } = useArchivoDeMensaje(props.message);

const abriendo = ref(false);
const caja     = ref(null);

const descargando = computed(() => props.message.media_status === 'pending');
const fallido     = computed(() => props.message.media_status === 'failed');
const esImagen    = computed(() => props.message.message_type === 'image');
const esAudio     = computed(() => props.message.message_type === 'audio');
const esVideo     = computed(() => props.message.message_type === 'video');

/**
 * Este archivo se abre en una pestana, o se descarga.
 *
 * Lo decide el servidor (`MediaStore::seMuestraEnLinea`) y viaja en el mensaje. No se decide
 * aqui a proposito: si el panel tuviera su propia lista, podria ofrecer "Abrir" sobre un
 * archivo que el servidor considera peligroso de mostrar.
 */
const seAbre = computed(() => props.message.media_inline === true);

const ICONOS = {
    document: 'pi-file-pdf',
    video   : 'pi-video',
};

const nombre         = computed(() => nombreVisible(props.message));
const nombreDescarga = computed(() => nombreDeDescarga(props.message));
const icono          = computed(() => ICONOS[props.message.message_type] ?? 'pi-file');

const meta = computed(() => {
    const partes = [etiquetaDeTipo(props.message.message_type)].filter(Boolean);
    const peso   = formatPeso(props.message.media_size);

    if (peso) partes.push(peso);

    return partes.join(' · ');
});

/** Baja el video y lo muestra en la burbuja. Solo al darle play, nunca solo. */
async function verVideo() {
    abriendo.value = true;
    await bajar();
    abriendo.value = false;
}

/**
 * Abre el archivo si se puede mostrar; si no, lo descarga.
 *
 * La pestana se abre EN el click, antes de esperar la descarga. Un `window.open` posterior a
 * un `await` ya no cuenta como gesto del usuario y el navegador lo bloquea: al operador le
 * salia la barra de "ventana emergente bloqueada" cada vez que abria un PDF.
 *
 * Pase lo que pase el operador se queda con el archivo: si el bloqueador gana de todas
 * formas, se descarga.
 */
async function abrirArchivo() {
    // Sin `noopener`: con esa opcion `window.open` devuelve null y no habria a quien darle la
    // direccion. El archivo es una URL `blob:` de nuestro propio origen.
    const ventana = (seAbre.value && ! url.value) ? window.open('', '_blank') : null;

    abriendo.value = true;
    const destino  = url.value ?? await bajar();
    abriendo.value = false;

    if (! destino) {
        ventana?.close();
        return;
    }

    // `inline` del servidor manda sobre `seAbre`: si por lo que sea no coinciden, gana el que
    // dice que NO se puede mostrar.
    if (! seAbre.value || inline.value === false) {
        ventana?.close();
        guardarComo(destino, nombreDescarga.value);
        return;
    }

    if (ventana && ! ventana.closed) {
        ventana.location.href = destino;
        return;
    }

    // Quedo bloqueado de todas formas: que al menos se lo lleve.
    if (! window.open(destino, '_blank')) guardarComo(destino, nombreDescarga.value);
}

onMounted(() => {
    // Solo lo que se muestra en la burbuja se precarga; el video y el documento esperan.
    if (! (esImagen.value || esAudio.value) || descargando.value || fallido.value) return;

    bajarCuandoSeVea(caja.value);
});
</script>

<style scoped>
.att { margin-bottom: 4px; }

.att-note {
    display: flex; align-items: center; gap: 6px;
    font-size: .75rem; font-style: italic; opacity: .85;
}
.att-note--error { font-style: normal; opacity: 1; }

.att-img-btn {
    display: block; padding: 0; border: 0; background: none; cursor: pointer;
    border-radius: 10px; overflow: hidden; line-height: 0;
}
.att-img-btn:disabled { cursor: default; }
.att-img { display: block; max-width: 240px; max-height: 280px; object-fit: cover; }

.att-img-skeleton {
    display: flex; align-items: center; justify-content: center;
    width: 180px; height: 120px; border-radius: 10px;
    background: var(--p-surface-200); color: var(--p-text-muted-color);
}

.att-video                 { max-width: 260px; }
.att-video-player          { display: block; width: 100%; border-radius: 10px; }
.att-video-poster {
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px;
    width: 240px; height: 135px; padding: 0; cursor: pointer;
    border: 0; border-radius: 10px;
    background: var(--p-surface-800); color: var(--p-surface-0);
}
.att-video-poster:disabled { cursor: default; }
.att-video-poster i        { font-size: 2rem; }
.att-video-poster span     { font-size: .78rem; font-weight: 600; }
.att-video-poster small    { font-size: .68rem; opacity: .75; }

.att-audio audio { display: block; max-width: 240px; height: 36px; }

.att-file {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 4px 6px 8px; border-radius: 8px;
    background: rgba(0, 0, 0, .06);
}
.att-file-icon { font-size: 1.3rem; flex-shrink: 0; }
.att-file-info { display: flex; flex-direction: column; min-width: 0; }
.att-file-name {
    font-size: .78rem; font-weight: 600;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 170px;
}
.att-file-meta { font-size: .68rem; opacity: .75; }
</style>
