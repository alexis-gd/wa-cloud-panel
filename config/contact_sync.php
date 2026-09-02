<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Sincronización de contactos desde el API del cliente
    |---------------------------------------------------------------------------
    |
    | El API NO es nuestro: lo expone un sistema del cliente en su red local
    | (192.168.17.20). Por eso todo lo que puede cambiar de un día para otro vive
    | aquí y no en el código: la URL, el puerto, cómo autentica y CÓMO SE LLAMAN
    | LOS CAMPOS de su respuesta.
    |
    | Cuando nos den la documentación, ajustar el `.env` y correr
    | `php artisan contactos:probar-api` - no hay que tocar código.
    |
    */

    // URL completa del endpoint que lista los contactos. Ej:
    // http://192.168.17.20:8085/api/clientes
    'url' => env('SYNC_API_URL'),

    /*
    | Autenticación. Dos modos:
    |   basic  -> usuario y contraseña (lo que mandó Joseph)
    |   bearer -> un token fijo en la cabecera Authorization
    | Si su API exige login previo para obtener token, eso se agrega cuando se
    | conozca el flujo; hoy no se inventa.
    */
    'auth'     => env('SYNC_API_AUTH', 'basic'),
    'user'     => env('SYNC_API_USER'),
    'password' => env('SYNC_API_PASSWORD'),
    'token'    => env('SYNC_API_TOKEN'),

    'timeout' => (int) env('SYNC_API_TIMEOUT', 30),

    /*
    | Dónde vive el arreglo de contactos DENTRO de la respuesta. Se lee con
    | `data_get`, así que admite rutas anidadas:
    |
    |   ''            -> la respuesta ES el arreglo:            [ {...}, {...} ]
    |   'data'        -> { "data": [ {...} ] }
    |   'result.items'-> { "result": { "items": [ {...} ] } }
    */
    'root' => env('SYNC_API_ROOT', ''),

    /*
    | Cómo se llaman los campos en SU respuesta. Se aceptan varios nombres
    | separados por coma: se usa el primero que venga con dato. Así un cambio de
    | nombre de campo de su lado no rompe la sincronización.
    */
    'field_phone' => env('SYNC_FIELD_PHONE', 'telefono,celular,phone,numero'),
    'field_name'  => env('SYNC_FIELD_NAME',  'nombre,name,cliente,nombre_completo'),

    /*
    | Etiqueta que se le pone a todo contacto dado de alta por esta vía. Deja
    | segmentar en campañas "los que vinieron del sistema del cliente".
    | Vacío = no etiquetar.
    */
    'tag' => env('SYNC_TAG'),

];
