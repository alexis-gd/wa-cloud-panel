<?php

use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// Health check — sin autenticación
Route::get('/health', function () {
    try {
        \DB::connection()->getPdo();
        $db = 'ok';
    } catch (\Exception $e) {
        $db = 'error';
    }
    return response()->json(['status' => 'ok', 'db' => $db]);
});

// Webhook de Meta — sin API key, pero valida X-Hub-Signature-256
Route::get('/webhook',  [WebhookController::class, 'verify']);
Route::post('/webhook', [WebhookController::class, 'handle']);

// Rutas protegidas con X-API-Key + rate limiting
Route::middleware(['api_key', 'throttle:60,1'])->group(function () {
    Route::get('/templates',           [TemplateController::class, 'index']);
    Route::post('/templates/send-test', [TemplateController::class, 'sendTest']);
    Route::get('/dashboard/stats',     [DashboardController::class, 'stats']);
});
