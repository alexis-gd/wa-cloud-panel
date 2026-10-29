<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ffmpeg
    |--------------------------------------------------------------------------
    |
    | Ruta al binario de ffmpeg. Solo se usa para las notas de voz: el navegador
    | las graba en WebM y WhatsApp no acepta ese formato, asi que hay que pasarlas
    | a Ogg antes de subirlas.
    |
    | Si el servidor no tiene ffmpeg, el panel esconde el boton del microfono en
    | vez de dejar que el envio falle. El resto de los adjuntos no lo necesitan.
    |
    */

    'ffmpeg' => env('FFMPEG_PATH', 'ffmpeg'),

];
