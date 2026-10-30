<?php

return [
    // Plantilla de Autenticacion aprobada en Meta. El texto lo pone Meta, nosotros solo el nombre.
    'template' => env('OTP_TEMPLATE', 'prestamaz_otp_v1'),

    'language' => env('OTP_LANGUAGE', 'es_MX'),

    /*
     * Porcentaje del techo diario de Meta que se le aparta al OTP.
     *
     * El limite de Meta es por cuenta y no distingue categorias: una campana grande se puede
     * comer todo el cupo del dia y dejar a los usuarios del cliente sin poder entrar a su app.
     * Las campanas frenan al llegar al (100 - este porcentaje); el OTP sigue hasta el tope real.
     */
    'reserve_percent' => (int) env('OTP_RESERVE_PERCENT', 10),
];
