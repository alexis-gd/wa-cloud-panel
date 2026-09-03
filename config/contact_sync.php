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
    | Autenticación. Modos:
    |   login  -> hace POST al endpoint de login, saca el token y lo usa (EL DE ESTE API)
    |   basic  -> usuario y contraseña en cada petición
    |   bearer -> un token fijo puesto a mano en el .env
    |   none   -> sin autenticación
    |
    | El API del cliente usa `login`: devuelve un JWT que caduca en 5 MINUTOS, así que un
    | token fijo en el .env no sirve - caducaría mucho antes del siguiente cron. Por eso se
    | pide uno nuevo en cada corrida.
    */
    'auth'     => env('SYNC_API_AUTH', 'login'),
    'user'     => env('SYNC_API_USER'),
    'password' => env('SYNC_API_PASSWORD'),
    'token'    => env('SYNC_API_TOKEN'),

    /*
    | Solo para el modo `login`. Los nombres de los campos son configurables porque este
    | API espera `usuario`, no `username`.
    */
    'login_url'      => env('SYNC_API_LOGIN_URL'),
    'login_user_key' => env('SYNC_API_LOGIN_USER_KEY', 'usuario'),
    'login_pass_key' => env('SYNC_API_LOGIN_PASS_KEY', 'password'),

    // Dónde viene el token en la respuesta del login. Se lee con `data_get`.
    'token_path' => env('SYNC_API_TOKEN_PATH', 'token'),

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

    /*
    |---------------------------------------------------------------------------
    | Estado del cliente en SU sistema
    |---------------------------------------------------------------------------
    |
    | El API devuelve un campo `Estado` con valores como LIQUIDADO, BURO o BAJA. Es su
    | clasificacion de cartera, NO nuestro opt-out: su "BAJA" significa que termino su
    | relacion con ellos, no que la persona pidio dejar de recibir mensajes.
    |
    | Por eso NO se filtra por default: se dan de alta todos y se etiqueta a cada quien
    | con su estado, para que el operador pueda segmentar campanas (por ejemplo, ofrecer
    | renovacion solo a los LIQUIDADO). Si el cliente decide excluir alguno, se pone aqui
    | sin tocar codigo.
    */
    'field_status' => env('SYNC_FIELD_STATUS', 'estado,status,situacion'),

    // Solo dar de alta estos (lista por comas). Vacio = todos.
    'status_include' => env('SYNC_STATUS_INCLUDE'),

    // Nunca dar de alta estos (lista por comas). Gana sobre include.
    'status_exclude' => env('SYNC_STATUS_EXCLUDE'),

    // Etiquetar a cada contacto nuevo con su estado (LIQUIDADO, BURO...). Muy util para
    // segmentar; se puede apagar con SYNC_TAG_FROM_STATUS=false.
    'tag_from_status' => filter_var(env('SYNC_TAG_FROM_STATUS', true), FILTER_VALIDATE_BOOL),

];
