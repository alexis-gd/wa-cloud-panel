<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    // En producción: solo el dominio del panel (APP_URL).
    // En desarrollo: también el dev server de Vite (puerto 5173).
    'allowed_origins' => array_filter([
        env('APP_URL', 'http://localhost'),
        env('APP_ENV') === 'local' ? 'http://localhost:5173' : null,
        env('APP_ENV') === 'local' ? 'http://127.0.0.1:5173' : null,
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-API-Key', 'Accept'],

    'exposed_headers' => [],

    // 2 horas — el navegador cachea el preflight, evita una request OPTIONS extra
    // en cada llamada a la API durante la sesión activa.
    'max_age' => 7200,

    'supports_credentials' => false,

];
