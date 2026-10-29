import { ref, onBeforeUnmount } from 'vue';
import { api } from '../api.js';

/**
 * El ciclo de vida del archivo de un mensaje: pedirlo, tenerlo, soltarlo.
 *
 * Lo comparten los cuatro tipos de adjunto, que por lo demás se ven y se comportan distinto.
 * Vive aparte del componente porque tiene tres cosas delicadas que no conviene repetir:
 *
 * 1. **La sesión.** Un `<img src="/api/...">` no manda el encabezado de sesión, así que el
 *    archivo se pide con `fetch` y se convierte en una URL local (`blob:`).
 * 2. **La memoria.** Esa URL hay que revocarla al desmontar, o el navegador se queda el
 *    archivo mientras viva la pestaña. Un turno abriendo conversaciones con fotos acaba
 *    comiéndose cientos de megas.
 * 3. **El veredicto del servidor.** Él decide si un archivo se puede mostrar o hay que
 *    descargarlo, y esa respuesta viaja con la descarga.
 */
export function useArchivoDeMensaje(message) {
    const url    = ref(null);
    const error  = ref(null);
    const inline = ref(null);

    let observer = null;

    /** Pide el archivo. Devuelve su URL local, o null si no se pudo. */
    async function bajar() {
        if (url.value || error.value) return url.value;

        const res = await api.conversationMedia(message.id);

        if (res.status !== 'ok') {
            error.value = res.message;

            return null;
        }

        url.value    = res.url;
        inline.value = res.inline;

        return res.url;
    }

    /**
     * Baja el archivo cuando el elemento entra a la pantalla, no antes.
     *
     * En un chat con 50 fotos, bajarlas todas al abrir la conversación tira decenas de
     * peticiones a la vez y el chat tarda en aparecer. Las que el operador nunca alcanza a ver
     * no se bajan nunca.
     */
    function bajarCuandoSeVea(elemento) {
        // Sin IntersectionObserver (navegador viejo) se baja de inmediato: peor rendimiento,
        // pero el operador ve su archivo.
        if (typeof IntersectionObserver === 'undefined') {
            bajar();

            return;
        }

        observer = new IntersectionObserver((entries) => {
            if (! entries.some(e => e.isIntersecting)) return;

            soltarObservador();
            bajar();
        }, { rootMargin: '200px' });

        if (elemento) observer.observe(elemento);
    }

    function soltarObservador() {
        observer?.disconnect();
        observer = null;
    }

    onBeforeUnmount(() => {
        soltarObservador();

        if (url.value) URL.revokeObjectURL(url.value);
    });

    return { url, error, inline, bajar, bajarCuandoSeVea };
}
