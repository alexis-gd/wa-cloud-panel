<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OtpCode;
use App\Services\Otp\OtpCapacity;
use App\Support\PageSize;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La tabla de codigos de verificacion del panel.
 *
 * Es solo de lectura: aqui nadie manda ni reenvia codigos, los pide la app del cliente. Sirve
 * para contestar "le llego o no le llego" cuando un usuario llama diciendo que no puede entrar.
 *
 * **El codigo no se muestra nunca, ni al superadmin.** Si el panel lo enseñara, un OTP dejaria
 * de probar que la persona tiene el telefono en la mano: bastaria con tener el panel abierto.
 */
class OtpLogController extends Controller
{
    private const PER_PAGE = 30;

    /**
     * GET /api/otp/log?q=...&status=...&page=1
     */
    public function index(Request $request): JsonResponse
    {
        $query = OtpCode::query();

        if ($request->filled('q')) {
            $term = $request->input('q');
            $query->where(function ($q) use ($term) {
                $q->where('phone', 'like', "%{$term}%")
                  ->orWhere('reference', 'like', "%{$term}%");
            });
        }

        if (in_array($request->input('status'), ['pending', 'sent', 'verified', 'expired', 'failed'], true)) {
            $query->where('status', $request->input('status'));
        }

        $pagina = $query->latest('id')->paginate(PageSize::from($request, self::PER_PAGE));

        return response()->json([
            'status' => 'ok',
            'data'   => collect($pagina->items())->map(fn (OtpCode $o) => [
                'id'            => $o->id,
                'phone'         => $o->phone,
                'reference'     => $o->reference,
                'status'        => $o->status,
                'attempts'      => $o->attempts,
                'created_at'    => $this->cst($o->created_at),
                'sent_at'       => $this->cst($o->sent_at),
                'verified_at'   => $this->cst($o->verified_at),
                'error_message' => $o->error_message,
            ])->all(),
            'meta' => [
                'total'    => $pagina->total(),
                'page'     => $pagina->currentPage(),
                'per_page' => $pagina->perPage(),
                'resumen'  => $this->resumen(),
            ],
        ]);
    }

    /**
     * El dia de hoy de un vistazo, mas el cupo que queda.
     *
     * El cupo va aqui porque es la pregunta que sigue cuando algo falla: si los OTP no salen,
     * lo primero que hay que descartar es que las campanas se hayan comido el dia.
     */
    private function resumen(): array
    {
        $inicio = now('America/Mexico_City')->startOfDay()->utc();
        $fin    = now('America/Mexico_City')->endOfDay()->utc();

        $hoy = OtpCode::whereBetween('created_at', [$inicio, $fin])
            ->selectRaw('status, COUNT(*) total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pedidos_hoy'    => (int) $hoy->sum(),
            'verificados'    => (int) $hoy->get('verified', 0),
            'fallidos'       => (int) $hoy->get('failed', 0),
            'cupo_restante'  => OtpCapacity::quedanParaOtp(),
        ];
    }

    /** La BD guarda UTC; al operador se le entrega su hora, ya formateada. */
    private function cst(?\Illuminate\Support\Carbon $fecha): ?string
    {
        return $fecha?->setTimezone('America/Mexico_City')->format('Y-m-d H:i');
    }
}
