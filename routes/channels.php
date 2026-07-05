<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Canal de conversaciones/mensajes entrantes: solo roles del panel que ven el chat.
// Los eventos en vivo (respuestas SMS/WhatsApp) se emiten aqui.
Broadcast::channel('conversations', function ($user) {
    return in_array($user->role, ['admin', 'operator', 'agent'], true);
});
