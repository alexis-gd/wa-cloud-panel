<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Models\PhoneNumber;
use App\Models\Setting;
use App\Services\WhatsApp\WhatsAppClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SettingsController extends Controller
{
    /**
     * GET /api/settings/token-status
     * Verifica si el token actual en DB es válido contra Meta.
     */
    public function tokenStatus(): JsonResponse
    {
        $phone = PhoneNumber::where('is_active', true)->first();

        if (! $phone) {
            return response()->json(['error' => 'No hay número de teléfono activo'], 404);
        }

        $result = $this->verifyTokenWithMeta($phone->token);

        return response()->json([
            'phone_number_id' => $phone->phone_number_id,
            'display_name'    => $phone->display_name,
            'token_valid'     => $result['valid'],
            'token_user'      => $result['user'] ?? null,
            'token_error'     => $result['error'] ?? null,
        ]);
    }

    /**
     * POST /api/settings/token
     * Actualiza el token del número activo.
     * Body: { "token": "EAAU..." }
     */
    public function updateToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string|min:50',
        ]);

        // Verificar que el token es válido antes de guardar
        $check = $this->verifyTokenWithMeta($data['token']);

        if (! $check['valid']) {
            return response()->json([
                'error'   => 'Token inválido — Meta rechazó la verificación',
                'details' => $check['error'] ?? null,
            ], 422);
        }

        $phone = PhoneNumber::where('is_active', true)->firstOrFail();
        $phone->token = $data['token']; // Eloquent cifra automáticamente con encryptString
        $phone->save();

        return response()->json([
            'message'    => 'Token actualizado correctamente',
            'token_user' => $check['user'],
        ]);
    }

    /**
     * GET /api/settings/phone-health
     * Devuelve calidad del número (GREEN/YELLOW/RED), modo (SANDBOX/LIVE),
     * tier/daily_limit desde BD, y estado del token.
     */
    public function phoneHealth(): JsonResponse
    {
        $phone = PhoneNumber::where('is_active', true)->first();

        if (! $phone) {
            return response()->json(['status' => 'error', 'message' => 'No hay número activo'], 404);
        }

        $client = new WhatsAppClient();
        $result = $client->get(
            $phone->phone_number_id,
            $phone->token,
            ['fields' => 'quality_rating,account_mode,display_phone_number,verified_name']
        );

        if (! $result['ok']) {
            $error = $result['body']['error']['message'] ?? 'Error al consultar Meta';
            $code  = $result['body']['error']['code']    ?? null;
            return response()->json([
                'status'  => 'error',
                'message' => $error,
                'code'    => $code,
            ], 422);
        }

        $meta = $result['body'];

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'display_phone'  => $meta['display_phone_number'] ?? null,
                'verified_name'  => $meta['verified_name']        ?? null,
                'quality_rating' => $meta['quality_rating']       ?? 'UNKNOWN',
                'account_mode'   => $meta['account_mode']         ?? 'UNKNOWN',
                'daily_limit'    => $phone->daily_limit,
                'sent_today'     => MessageLog::where('phone_number_id', $phone->id)
                                        ->whereBetween('sent_at', [
                                            now('America/Mexico_City')->startOfDay()->utc(),
                                            now('America/Mexico_City')->endOfDay()->utc(),
                                        ])
                                        ->whereIn('status', ['sent', 'delivered', 'read'])
                                        ->count(),
                'is_active'      => $phone->is_active,
                'is_paused'      => $phone->isPaused(),
                'paused_until'   => $phone->paused_until?->setTimezone('America/Mexico_City')->format('Y-m-d H:i'),
            ],
        ]);
    }

    /**
     * GET /api/settings/cooldown
     * Devuelve el valor actual de cooldown_days.
     */
    public function getCooldown(): JsonResponse
    {
        $days = max(7, (int) Setting::get('cooldown_days', 30));

        return response()->json([
            'status' => 'ok',
            'data'   => ['cooldown_days' => $days],
        ]);
    }

    /**
     * PUT /api/settings/cooldown
     * Actualiza cooldown_days. Mínimo forzado: 7.
     * Body: { "cooldown_days": 15 }
     */
    public function updateCooldown(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cooldown_days' => 'required|integer|min:7|max:365',
        ]);

        Setting::set('cooldown_days', $data['cooldown_days']);

        return response()->json([
            'status' => 'ok',
            'data'   => ['cooldown_days' => $data['cooldown_days']],
        ]);
    }

    // ── Feature flags ────────────────────────────────────────────────────────────

    private const FEATURE_FLAGS = [
        // Módulos (nav items)
        'feature_dashboard',
        'feature_contacts',
        'feature_campaigns',
        'feature_templates',
        'feature_users',
        'feature_conversations',
        // Sub-features dentro de módulos
        'feature_daily_chart',
        'feature_export',
        'feature_tags',
        'feature_multi_agent',
    ];

    /**
     * GET /api/settings/features
     * Devuelve todos los feature flags activos/inactivos.
     * Público para roles autenticados (no solo admin) — el frontend lo usa al login.
     */
    public function getFeatures(): JsonResponse
    {
        $flags = [];
        foreach (self::FEATURE_FLAGS as $key) {
            $flags[$key] = (bool) (int) Setting::get($key, '1');
        }

        return response()->json(['status' => 'ok', 'data' => $flags]);
    }

    /**
     * PUT /api/settings/features
     * Actualiza uno o varios feature flags.
     * Body: { "feature_conversations": false, "feature_export": true }
     */
    public function updateFeatures(Request $request): JsonResponse
    {
        $data = $request->validate(
            collect(self::FEATURE_FLAGS)->mapWithKeys(fn ($k) => [$k => 'boolean'])->toArray()
        );

        foreach ($data as $key => $value) {
            Setting::set($key, $value ? '1' : '0');
        }

        return response()->json(['status' => 'ok', 'data' => $data]);
    }

    // ── Modo de asignación automática ────────────────────────────────────────────

    /**
     * GET /api/settings/assignment-mode
     * Devuelve el modo de auto-asignación de conversaciones.
     */
    public function getAssignmentMode(): JsonResponse
    {
        $mode = Setting::get('assignment_mode', 'least_chats');

        return response()->json(['status' => 'ok', 'data' => ['assignment_mode' => $mode]]);
    }

    /**
     * PUT /api/settings/assignment-mode
     * Actualiza el modo de auto-asignación.
     * Body: { "assignment_mode": "least_chats" | "first_available" }
     */
    public function updateAssignmentMode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'assignment_mode' => 'required|in:least_chats,first_available',
        ]);

        Setting::set('assignment_mode', $data['assignment_mode']);

        return response()->json(['status' => 'ok', 'data' => $data]);
    }

    // ── Meta mensual ────────────────────────────────────────────────────────────

    /**
     * GET /api/settings/monthly-goal
     * Devuelve la meta mensual de envíos (default 200 000).
     */
    public function getMonthlyGoal(): JsonResponse
    {
        $goal = (int) Setting::get('monthly_goal', 200000);

        return response()->json(['status' => 'ok', 'data' => ['monthly_goal' => $goal]]);
    }

    /**
     * PUT /api/settings/monthly-goal
     * Actualiza la meta mensual.
     * Body: { "monthly_goal": 200000 }
     */
    public function updateMonthlyGoal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'monthly_goal' => 'required|integer|min:1|max:10000000',
        ]);

        Setting::set('monthly_goal', $data['monthly_goal']);

        return response()->json(['status' => 'ok', 'data' => $data]);
    }

    // ── Auto-blacklist SMS por rebotes ───────────────────────────────────────────

    /**
     * GET /api/settings/sms-auto-blacklist
     * Devuelve el umbral de rebotes SMS antes de auto-bloquear (0 = nunca bloquea).
     */
    public function getSmsAutoBlacklist(): JsonResponse
    {
        $bounces = (int) Setting::get('sms_auto_blacklist_bounces', 0);

        return response()->json(['status' => 'ok', 'data' => ['sms_auto_blacklist_bounces' => $bounces]]);
    }

    /**
     * PUT /api/settings/sms-auto-blacklist
     * Actualiza el umbral. 0 = desactivado (el cliente es blando con SMS).
     * Body: { "sms_auto_blacklist_bounces": 3 }
     */
    public function updateSmsAutoBlacklist(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sms_auto_blacklist_bounces' => 'required|integer|min:0|max:20',
        ]);

        Setting::set('sms_auto_blacklist_bounces', $data['sms_auto_blacklist_bounces']);

        return response()->json(['status' => 'ok', 'data' => $data]);
    }

    /**
     * GET /api/settings/sms-webhook-health
     * Muestra cuándo llegó el último evento del webhook SMS (delivered/received/etc).
     * Si no llega hace rato, el gateway dejó de entregar eventos (revisar teléfono/gateway).
     */
    public function smsWebhookHealth(): JsonResponse
    {
        $parse = fn (?string $v) => $v ? \Carbon\Carbon::parse($v) : null;
        $fmt   = fn (?\Carbon\Carbon $c) => $c?->setTimezone('America/Mexico_City')->format('Y-m-d H:i');
        $ago   = fn (?\Carbon\Carbon $c) => $c ? (int) $c->diffInMinutes(now()) : null;

        $lastAt       = $parse(Setting::get('sms_webhook_last_at'));        // procesado OK
        $lastHit      = $parse(Setting::get('sms_webhook_last_hit_at'));    // cualquier llegada
        $lastRejected = $parse(Setting::get('sms_webhook_last_rejected_at')); // rechazado por firma
        $lastEvent    = Setting::get('sms_webhook_last_event');

        // Diagnóstico directo del estado del canal de vuelta.
        if (! $lastHit) {
            $diagnosis = 'no_hits';   // el gateway no está mandando NADA al panel
        } elseif ($lastRejected && (! $lastAt || $lastRejected->gte($lastAt))) {
            $diagnosis = 'signature'; // llegan eventos pero se rechazan por firma
        } elseif ($lastAt && $ago($lastAt) <= 1440) {
            $diagnosis = 'ok';        // procesando eventos con firma válida
        } else {
            $diagnosis = 'stale';     // procesó antes, pero hace mucho que no llega uno bueno
        }

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'diagnosis'         => $diagnosis,
                'healthy'           => $diagnosis === 'ok',
                'last_event'        => $lastEvent,
                'last_at'           => $fmt($lastAt),
                'last_at_ago'       => $ago($lastAt),
                'last_hit_at'       => $fmt($lastHit),
                'last_hit_ago'      => $ago($lastHit),
                'last_rejected_at'  => $fmt($lastRejected),
                'last_rejected_ago' => $ago($lastRejected),
                'ever'              => $lastHit !== null,
            ],
        ]);
    }

    public function demoReset(): JsonResponse
    {
        $phones = PhoneNumber::query()->update(['paused_until' => null]);

        // Retrocede sent_at un año para que ningún contacto esté en cooldown
        $logs = MessageLog::query()->update(['sent_at' => now()->subYear()]);

        // Limpia las bajas de SMS (opt-out/bloqueo/inválido) para que el número de demo
        // pueda volver a recibir SMS. Solo aplica al modo demo (herramienta de superadmin).
        $smsCleared = Contact::query()->update([
            'sms_opt_out'      => false,
            'sms_blocked'      => false,
            'sms_invalid'      => false,
            'sms_bounce_count' => 0,
        ]);

        return response()->json([
            'status' => 'ok',
            'data'   => [
                'phones_unpaused' => $phones,
                'logs_reset'      => $logs,
                'sms_cleared'     => $smsCleared,
            ],
        ]);
    }

    /**
     * Llama a graph.facebook.com/me para verificar si el token es válido.
     */
    private function verifyTokenWithMeta(string $token): array
    {
        $response = Http::timeout(10)
            ->get('https://graph.facebook.com/v22.0/me', [
                'access_token' => $token,
            ]);

        if ($response->successful() && isset($response->json()['name'])) {
            return ['valid' => true, 'user' => $response->json()['name']];
        }

        $error = $response->json()['error']['message'] ?? 'Error desconocido';
        return ['valid' => false, 'error' => $error];
    }
}
