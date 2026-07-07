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
// Los eventos en vivo (respuestas SMS/WhatsApp) se emiten aqui. superadmin incluido
// (siempre pasa, igual que bypasa el role middleware en el resto del panel).
Broadcast::channel('conversations', function ($user) {
    return in_array($user->role, ['superadmin', 'admin', 'operator', 'agent'], true);
});

// Canal de progreso de campanas: el worker emite CampaignProgressUpdated mientras
// envia (contadores + estado). La tabla y el modal suben solos, sin polling.
// superadmin incluido (si no, /broadcasting/auth da 403 y no llega nada).
Broadcast::channel('campaigns', function ($user) {
    return in_array($user->role, ['superadmin', 'admin', 'operator', 'agent'], true);
});

// Canal de notificaciones (campanita): se emite NotificationCreated al crear una
// AppNotification. La campanita se prende al instante, sin polling. superadmin incluido.
Broadcast::channel('notifications', function ($user) {
    return in_array($user->role, ['superadmin', 'admin', 'operator', 'agent'], true);
});

// Canal del Dashboard: semaforo del numero (PhoneNumberPaused). El resto del dashboard
// (cifras, ultimos mensajes, envios al dia) se refresca on-event via el canal 'campaigns'.
// superadmin incluido (si no, /broadcasting/auth da 403 y no llega nada).
Broadcast::channel('dashboard', function ($user) {
    return in_array($user->role, ['superadmin', 'admin', 'operator', 'agent'], true);
});
