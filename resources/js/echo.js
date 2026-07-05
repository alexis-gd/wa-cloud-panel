/**
 * Tiempo real via WebSocket (Soketi, compatible con el protocolo Pusher).
 *
 * DISENO ADITIVO: si no hay config (VITE_PUSHER_APP_KEY vacio) o Soketi no corre,
 * initEcho() devuelve null y la app sigue funcionando igual que siempre con sus
 * pollings. El tiempo real solo se activa cuando el servidor WS esta configurado.
 * Asi se puede desplegar sin riesgo aunque Soketi todavia no exista.
 *
 * Autenticacion de canales privados: Sanctum por token Bearer (el mismo wa_token de
 * localStorage que usa la API). Se lee en cada authorize() para que sirva tras re-login.
 */
import Echo   from 'laravel-echo';
import Pusher from 'pusher-js';

let echo = null;

export function initEcho() {
    const key = import.meta.env.VITE_PUSHER_APP_KEY;

    // Sin config o ya inicializado: no hacer nada (la app usa sus pollings).
    if (! key || echo) return echo;

    window.Pusher = Pusher;

    const scheme   = import.meta.env.VITE_PUSHER_SCHEME ?? 'https';
    const forceTLS = scheme === 'https';

    echo = new Echo({
        broadcaster       : 'pusher',
        key,
        cluster           : import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
        wsHost            : import.meta.env.VITE_PUSHER_HOST ?? window.location.hostname,
        wsPort            : Number(import.meta.env.VITE_PUSHER_PORT ?? 6001),
        wssPort           : Number(import.meta.env.VITE_PUSHER_PORT ?? 443),
        forceTLS,
        enabledTransports : ['ws', 'wss'],
        disableStats      : true,
        authorizer: (channel) => ({
            authorize: (socketId, callback) => {
                fetch('/broadcasting/auth', {
                    method  : 'POST',
                    headers : {
                        'Content-Type' : 'application/json',
                        'Accept'       : 'application/json',
                        'Authorization': `Bearer ${localStorage.getItem('wa_token')}`,
                    },
                    body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
                })
                    .then(res => res.ok ? res.json() : Promise.reject(res))
                    .then(data => callback(null, data))
                    .catch(err => callback(err, null));
            },
        }),
    });

    return echo;
}

export function getEcho() {
    return echo;
}
