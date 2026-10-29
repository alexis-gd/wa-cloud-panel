/**
 * Cómo se nombran y se describen los archivos de las conversaciones.
 *
 * Vive aparte del componente porque lo necesitan dos lugares: la burbuja del adjunto y el
 * visor de fotos, que guarda con el mismo nombre. Y porque es la pareja en el navegador de
 * `MediaStore` en el servidor: si una de las dos tablas cambia, hay que mirar la otra.
 */

/** Espejo de `MediaStore::EXTENSIONES`. */
const EXTENSIONES = {
    'image/jpeg'     : 'jpg',
    'image/png'      : 'png',
    'image/webp'     : 'webp',
    'image/gif'      : 'gif',
    'application/pdf': 'pdf',
    'audio/ogg'      : 'ogg',
    'audio/mpeg'     : 'mp3',
    'audio/mp4'      : 'm4a',
    'audio/aac'      : 'aac',
    'audio/amr'      : 'amr',
    'video/mp4'      : 'mp4',
    'video/3gpp'     : '3gp',
};

/** Espejo de `Conversation::etiquetaDeTipo()`. */
export const ETIQUETAS = {
    image   : 'Foto',
    document: 'Documento',
    audio   : 'Nota de voz',
    video   : 'Video',
};

export function etiquetaDeTipo(tipo) {
    return ETIQUETAS[tipo] ?? '';
}

/**
 * Nombre con el que se guarda el archivo en la computadora del operador.
 *
 * Tiene que llevar extensión. Sin ella el navegador la adivina del tipo de archivo, y en
 * Windows `image/jpeg` está asociado a `.jfif` en el registro: una foto se bajaba como
 * `Foto.jfif` y varios programas no la abren. Solo los documentos traen nombre propio desde
 * WhatsApp; una foto o una nota de voz llegan sin nombre y caían justo en ese hueco.
 */
export function nombreDeDescarga(message) {
    const original = message.media_filename;

    if (original && /\.[a-z0-9]{2,5}$/i.test(original)) return original;

    const mime = (message.media_mime || '').split(';')[0].trim();
    const ext  = EXTENSIONES[mime];
    const base = original || `${ETIQUETAS[message.message_type] || 'Archivo'}-${message.id}`;

    return ext ? `${base}.${ext}` : base;
}

/** Nombre que se muestra en la ficha del archivo. */
export function nombreVisible(message) {
    return message.media_filename || ETIQUETAS[message.message_type] || 'Archivo';
}

export function formatPeso(bytes) {
    if (! bytes) return '';
    if (bytes < 1024)        return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * Baja un archivo que ya está en memoria.
 *
 * Un `<a download>` con una URL `blob:` es la única forma de guardar algo que ya se pidió al
 * servidor sin volver a pedirlo.
 */
export function guardarComo(url, nombre) {
    const a = document.createElement('a');

    a.href     = url;
    a.download = nombre;
    a.click();
}
