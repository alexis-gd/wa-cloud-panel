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
 * Por que es un componente aparte: cada adjunto maneja su propia descarga y su propia limpieza
 * de memoria, y la vista de Conversaciones ya es grande. Asi cada burbuja se encarga de lo suyo.
 *
 * Como llega el archivo: la ruta del panel exige sesion, y un `<img src="/api/...">` no manda
 * el encabezado de sesion. Entonces se pide con fetch y se convierte en una URL local
 * (`blob:`). Esa URL hay que revocarla al desmontar o el navegador se queda el archivo en
 * memoria mientras la pestana viva.
 *
 * Las fotos se bajan cuando entran a la pantalla, no al abrir la conversacion: en un chat con
 * 50 fotos, bajarlas todas de golpe tira decenas de peticiones a la vez y el chat tarda en
 * aparecer. Las fotos que el operador nunca alcanza a ver no se bajan nunca.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import Button from 'primevue/button';
import { api } from '../api.js';
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

const url      = ref(null);
const error    = ref(null);
const abriendo = ref(false);
// Veredicto del servidor, ya con el archivo en mano. Null hasta que se baja.
const inline   = ref(null);
const caja     = ref(null);

let observer = null;

const descargando = computed(() => props.message.media_status === 'pending');
const fallido     = computed(() => props.message.media_status === 'failed');
const esImagen    = computed(() => props.message.message_type === 'image');
const esAudio     = computed(() => props.message.message_type === 'audio');

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

async function bajar() {
    if (url.value || error.value) return null;

    const res = await api.conversationMedia(props.message.id);

    if (res.status !== 'ok') {
        error.value = res.message;
        return null;
    }

    url.value    = res.url;
    inline.value = res.inline;

    return res.url;
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
    // Solo lo que se muestra en la burbuja se precarga; el documento espera al click.
    if (! (esImagen.value || esAudio.value) || descargando.value || fallido.value) return;

    // Sin IntersectionObserver (navegador viejo) se baja de inmediato: peor rendimiento,
    // pero el operador ve su foto.
    if (typeof IntersectionObserver === 'undefined') {
        bajar();
        return;
    }

    observer = new IntersectionObserver((entries) => {
        if (! entries.some(e => e.isIntersecting)) return;
        observer?.disconnect();
        observer = null;
        bajar();
    }, { rootMargin: '200px' });

    if (caja.value) observer.observe(caja.value);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    // Sin esto el navegador se queda el archivo en memoria: un turno entero abriendo
    // conversaciones con fotos acaba comiendose cientos de megas.
    if (url.value) URL.revokeObjectURL(url.value);
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
