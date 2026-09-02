<?php

namespace App\Services\Tags;

use App\Models\Campaign;
use App\Models\Tag;

/**
 * Decide si una etiqueta se puede borrar y con qué consecuencias.
 *
 * Por qué existe: `campaigns.tag_id` es `nullOnDelete`. Al borrar la etiqueta, sus campañas
 * se quedan con `tag_id = NULL`, y el despacho hace `if ($campaign->tag_id) { segmentar }`
 * (CampaignController::execute). Sin `tag_id` ese `if` no entra y la campaña deja de estar
 * segmentada: una campaña de 500 contactos pasaría a apuntar a TODA la base al primer clic
 * en Ejecutar. Es justo el error que el sistema debe hacer imposible, y le pega a la cuenta
 * de Meta.
 *
 * Por eso: las campañas que TODAVÍA se pueden ejecutar (`draft` y `paused`, mismos estados
 * que valida `execute()`) bloquean el borrado. Las que ya corrieron no: perder el `tag_id`
 * solo les quita la referencia del segmento, su historial de envíos no cambia.
 */
class TagDeletionGuard
{
    /** Estados de campaña que todavía pueden despacharse (ver CampaignController::execute). */
    public const EXECUTABLE_STATUSES = ['draft', 'paused'];

    /**
     * Qué se lleva por delante borrar esta etiqueta.
     *
     * @return array{contacts:int, campaigns:int, blocking:array<int, array{id:int, name:string, status:string}>}
     */
    public static function usage(Tag $tag): array
    {
        $blocking = Campaign::where('tag_id', $tag->id)
            ->whereIn('status', self::EXECUTABLE_STATUSES)
            ->orderBy('id')
            ->get(['id', 'name', 'status'])
            ->map(fn (Campaign $c) => [
                'id'     => $c->id,
                'name'   => $c->name,
                'status' => $c->status,
            ])
            ->all();

        return [
            'contacts'  => $tag->contacts()->count(),
            'campaigns' => Campaign::where('tag_id', $tag->id)->count(),
            'blocking'  => $blocking,
        ];
    }

    /** ¿Hay alguna campaña sin ejecutar que se quedaría sin segmento? */
    public static function isBlocked(array $usage): bool
    {
        return $usage['blocking'] !== [];
    }

    /** Mensaje para el operador cuando el borrado está bloqueado. */
    public static function blockedMessage(array $usage): string
    {
        $names = collect($usage['blocking'])->pluck('name')->implode(', ');
        $count = count($usage['blocking']);

        return $count === 1
            ? "No se puede borrar: la campaña \"{$names}\" todavía no se ha enviado y usa esta etiqueta. Si la borras, esa campaña dejaría de estar segmentada y se enviaría a TODOS los contactos. Cámbiale el segmento o cancélala primero."
            : "No se puede borrar: {$count} campañas sin enviar usan esta etiqueta ({$names}). Si la borras, dejarían de estar segmentadas y se enviarían a TODOS los contactos. Cámbiales el segmento o cancélalas primero.";
    }
}
