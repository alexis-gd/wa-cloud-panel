<?php

namespace App\Services;

use App\Models\MessageLog;
use App\Models\PhoneNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PhoneNumberSelector
{
    /**
     * Devuelve la colección de números disponibles con capacidad restante hoy,
     * ordenados de mayor a menor capacidad (el menos saturado primero).
     *
     * Un número está disponible si:
     *  - is_active = true
     *  - no está pausado por circuit breaker (paused_until < now o null)
     *  - tiene al menos 1 mensaje restante de su daily_limit
     */
    public function available(): Collection
    {
        $numbers = PhoneNumber::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('paused_until')->orWhere('paused_until', '<', now()))
            ->get();

        if ($numbers->isEmpty()) {
            return collect();
        }

        $startOfDay = now('America/Mexico_City')->startOfDay()->utc();
        $endOfDay   = now('America/Mexico_City')->endOfDay()->utc();

        // Una sola query para obtener los enviados hoy por número
        $sentToday = MessageLog::whereBetween('sent_at', [$startOfDay, $endOfDay])
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->whereIn('phone_number_id', $numbers->pluck('id'))
            ->select('phone_number_id', DB::raw('COUNT(*) as total'))
            ->groupBy('phone_number_id')
            ->pluck('total', 'phone_number_id');

        return $numbers
            ->map(function (PhoneNumber $pn) use ($sentToday): PhoneNumber {
                $pn->remaining = max(0, $pn->daily_limit - (int) ($sentToday[$pn->id] ?? 0));
                return $pn;
            })
            ->filter(fn (PhoneNumber $pn) => $pn->remaining > 0)
            ->sortByDesc('remaining')
            ->values();
    }
}
