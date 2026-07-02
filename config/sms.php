<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SMS Gateway (SMS Gateway for Android™ — capcom6, modo self-host/privado)
    |--------------------------------------------------------------------------
    | El pool de chips lo maneja el propio servidor gateway (round-robin entre
    | devices registrados). Desde Laravel hay UN solo endpoint — no una tabla de
    | gateways. Ver docs/sms-sim-propia-analisis.md.
    |
    | En modo privado self-host `url` apunta a tu servidor Docker; en modo local
    | (un solo teléfono en desarrollo) apunta a la IP del dispositivo.
    |
    | OJO con el prefijo de la ruta (difiere entre cloud y self-host):
    |   - Cloud:      https://api.sms-gate.app/3rdparty/v1
    |   - Self-host:  http://127.0.0.1:3000/api/3rdparty/v1   (lleva /api)
    | El cliente le agrega /messages. Endpoint final: {url}/messages (Basic auth).
    */
    'gateway' => [
        'url'      => env('SMS_GATEWAY_URL', 'https://api.sms-gate.app/3rdparty/v1'),
        'login'    => env('SMS_GATEWAY_LOGIN'),
        'password' => env('SMS_GATEWAY_PASSWORD'),
        'timeout'  => (int) env('SMS_GATEWAY_TIMEOUT', 15),
    ],

    // Secreto compartido para validar que el webhook de status viene del gateway.
    'webhook_secret' => env('SMS_WEBHOOK_SECRET'),
];
