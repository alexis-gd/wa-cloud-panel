<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

// ── Health check — público ───────────────────────────────────────────────────
Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $db = 'ok';
    } catch (\Exception) {
        $db = 'error';
    }
    return response()->json(['status' => 'ok', 'db' => $db]);
});

// ── Webhook Meta — sin API key, valida X-Hub-Signature-256 ──────────────────
Route::get('/webhook',  [WebhookController::class, 'verify']);
Route::post('/webhook', [WebhookController::class, 'handle']);

// ── Auth — público, con rate limit anti-brute-force ─────────────────────────
// 5 intentos por minuto por IP — bloquea ataques de fuerza bruta sin molestar
// a usuarios legítimos (un operador no hace 5 logins en 60 segundos).
Route::prefix('auth')->middleware('throttle:5,1')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
});

// ── Panel — requiere token Sanctum + rate limiting ───────────────────────────
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me',      [AuthController::class, 'me']);

    // Dashboard, templates, configuración — admin y operator
    Route::middleware('role:admin,operator')->group(function () {
        Route::get('/templates',             [TemplateController::class, 'index']);
        Route::get('/dashboard/stats',           [DashboardController::class, 'stats']);
        Route::get('/dashboard/messages',        [DashboardController::class, 'messages']);
        Route::get('/dashboard/daily-stats',     [DashboardController::class, 'dailyStats']);
        Route::get('/dashboard/monthly-history', [DashboardController::class, 'monthlyHistory']);

        // Contactos
        Route::get('/contacts',              [ContactController::class, 'index']);
        Route::get('/contacts/stats',        [ContactController::class, 'stats']);
        Route::get('/contacts/check',        [ContactController::class, 'check']);
        Route::post('/contacts',             [ContactController::class, 'store']);
        Route::post('/contacts/upload',      [ContactController::class, 'upload']);
        Route::post('/contacts/{id}/opt-out', [ContactController::class, 'optOut']);
        Route::put('/contacts/{id}/tags',    [TagController::class, 'syncContact']);
        Route::post('/contacts/tags/bulk-attach', [TagController::class, 'bulkAttach']);
        Route::post('/contacts/tags/bulk-detach', [TagController::class, 'bulkDetach']);

        // Tags
        Route::get('/tags',         [TagController::class, 'index']);
        Route::post('/tags',        [TagController::class, 'store']);
        Route::delete('/tags/{id}', [TagController::class, 'destroy']);

        // Campañas
        Route::get('/campaigns',                [CampaignController::class, 'index']);
        Route::post('/campaigns',               [CampaignController::class, 'store']);
        Route::get('/campaigns/{id}',           [CampaignController::class, 'show']);
        Route::get('/campaigns/{id}/logs',      [CampaignController::class, 'logs']);
        Route::post('/campaigns/{id}/execute',  [CampaignController::class, 'execute']);
        Route::post('/campaigns/{id}/pause',         [CampaignController::class, 'pause']);
        Route::post('/campaigns/{id}/retry-pending', [CampaignController::class, 'retryPending']);
        Route::delete('/campaigns/{id}',             [CampaignController::class, 'destroy']);
    });

    // Notificaciones — todos los roles autenticados
    Route::middleware('role:admin,operator,agent')->group(function () {
        Route::get('/notifications',              [NotificationController::class, 'index']);
        Route::post('/notifications/read-all',    [NotificationController::class, 'markReadAll']);
        Route::delete('/notifications/{id}',      [NotificationController::class, 'destroy']);
    });

    // Conversaciones (chat con contactos) — admin, operator y agent
    Route::middleware('role:admin,operator,agent')->group(function () {
        Route::get('/conversations',                            [ConversationController::class, 'index']);
        Route::get('/conversations/{contactId}',               [ConversationController::class, 'show']);
        Route::post('/conversations/{contactId}/messages',     [ConversationController::class, 'send']);
        Route::post('/conversations/{contactId}/claim',        [ConversationController::class, 'claim']);
        Route::get('/quick-replies',                           [ConversationController::class, 'quickReplies']);
    });

    // Asignación de conversaciones — solo admin y operator
    Route::middleware('role:admin,operator')->group(function () {
        Route::post('/conversations/{contactId}/assign', [ConversationController::class, 'assign']);
    });

    // Quick replies — solo admin puede crear/eliminar
    Route::middleware('role:admin')->group(function () {
        Route::post('/quick-replies',        [ConversationController::class, 'storeQuickReply']);
        Route::delete('/quick-replies/{id}', [ConversationController::class, 'destroyQuickReply']);
    });

    // Plantillas — escritura solo admin
    Route::middleware('role:admin')->group(function () {
        Route::post('/templates/send-test',  [TemplateController::class, 'sendTest']);
        Route::post('/templates',            [TemplateController::class, 'store']);
        Route::post('/templates/sync',       [TemplateController::class, 'sync']);
        Route::put('/templates/{id}',        [TemplateController::class, 'update']);
        Route::delete('/templates/{id}',     [TemplateController::class, 'destroy']);
    });

    // Exports (admin y operator pueden descargar)
    Route::middleware('role:admin,operator')->group(function () {
        Route::get('/export/contacts', [ExportController::class, 'contacts']);
        Route::get('/export/messages', [ExportController::class, 'messages']);
    });

    // Feature flags — GET para todos los roles autenticados
    Route::middleware('role:admin,operator,agent')->group(function () {
        Route::get('/settings/features', [SettingsController::class, 'getFeatures']);
    });

    // Info operativa — admin y operator pueden leer (dato del dashboard)
    Route::middleware('role:admin,operator')->group(function () {
        Route::get('/settings/phone-health', [SettingsController::class, 'phoneHealth']);
    });

    // Configuración — solo superadmin (superadmin bypasa el check en RoleMiddleware)
    Route::middleware('role:superadmin')->group(function () {
        Route::put('/settings/features',         [SettingsController::class, 'updateFeatures']);
        Route::get('/settings/token-status',     [SettingsController::class, 'tokenStatus']);
        Route::post('/settings/token',           [SettingsController::class, 'updateToken']);
        Route::get('/settings/cooldown',         [SettingsController::class, 'getCooldown']);
        Route::put('/settings/cooldown',         [SettingsController::class, 'updateCooldown']);
        Route::get('/settings/assignment-mode',  [SettingsController::class, 'getAssignmentMode']);
        Route::put('/settings/assignment-mode',  [SettingsController::class, 'updateAssignmentMode']);
        Route::get('/settings/monthly-goal',     [SettingsController::class, 'getMonthlyGoal']);
        Route::put('/settings/monthly-goal',     [SettingsController::class, 'updateMonthlyGoal']);
        Route::post('/settings/demo-reset',      [SettingsController::class, 'demoReset']);
    });

    // Operaciones admin (superadmin hereda vía bypass en RoleMiddleware)
    Route::middleware('role:admin')->group(function () {
        Route::put('/contacts/{id}',        [ContactController::class, 'update']);
        Route::delete('/contacts/{id}',     [ContactController::class, 'destroy']); // soft delete — solo admin/superadmin

        // Gestión de usuarios
        Route::get('/users',        [UserController::class, 'index']);
        Route::post('/users',       [UserController::class, 'store']);
        Route::put('/users/{id}',   [UserController::class, 'update']);
        Route::delete('/users/{id}',[UserController::class, 'destroy']);
    });
});
