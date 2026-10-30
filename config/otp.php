<?php

return [
    // Plantilla de Autenticacion aprobada en Meta. El texto lo pone Meta, nosotros solo el nombre.
    'template' => env('OTP_TEMPLATE', 'prestamaz_otp_v1'),

    'language' => env('OTP_LANGUAGE', 'es_MX'),

    /*
     * Segundos que hay que esperar entre dos codigos al MISMO numero.
     *
     * Frena el boton de "reenviar" nervioso y encarece el abuso. El estandar de la industria
     * ronda los 30-60 segundos (es lo que hay detras de los contadores de "reenviar en 0:45");
     * aqui va mas conservador por decision del cliente. Subirlo protege mas, pero si un mensaje
     * tarda, el usuario se queda esperando sin poder hacer nada: la app deberia enseñarle la
     * cuenta regresiva en vez de dejarlo picar el boton.
     */
    'resend_seconds' => (int) env('OTP_RESEND_SECONDS', 180),

    /*
     * Porcentaje del techo diario de Meta que se le aparta al OTP.
     *
     * El limite de Meta es por cuenta y no distingue categorias: una campana grande se puede
     * comer todo el cupo del dia y dejar a los usuarios del cliente sin poder entrar a su app.
     * Las campanas frenan al llegar al (100 - este porcentaje); el OTP sigue hasta el tope real.
     */
    'reserve_percent' => (int) env('OTP_RESERVE_PERCENT', 10),
];
