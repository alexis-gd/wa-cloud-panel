<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Los filtros de la pantalla de Contactos, en un solo lugar.
 *
 * Existe porque hay DOS consumidores que tienen que ver exactamente lo mismo: el listado
 * (`ContactController`) y el etiquetado masivo por filtro (`TagController::bulkAttachFiltered`).
 * Si cada uno armara su query, un día el operador etiquetaría un conjunto distinto del que
 * tiene enfrente - y estaría etiquetando a ciegas.
 */
class ContactFilters
{
    /** Los campos que este servicio entiende. Para reusar en la validación de los endpoints. */
    public const RULES = [
        'q'                => 'nullable|string|max:255',
        'status'           => 'nullable|string',
        'tag_id'           => 'nullable|integer',
        'portfolio_status' => 'nullable|string|max:60',
        'sms_blocked'      => 'nullable',
        'deliverability'   => 'nullable',
    ];

    /**
     * @param  array|null  $paste  Resultado de PhoneListParser cuando hubo pegado masivo.
     */
    public static function apply(Builder $query, Request $request, ?array $paste = null): void
    {
        // El pegado masivo manda sobre el buscador de texto: si el operador pegó una lista,
        // lo que quiere ver es exactamente esa lista.
        if ($paste !== null) {
            // Lista vacía tras normalizar -> whereIn([]) devuelve 0 filas, que es lo correcto
            // (pegó puros números inválidos); el resumen del pegado se lo explica.
            $query->whereIn('phone', $paste['phones']);
        } elseif ($request->filled('q')) {
            $term = $request->input('q');
            $query->where(function ($q) use ($term) {
                $q->where('phone', 'like', "%{$term}%")
                  ->orWhere('name',  'like', "%{$term}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('tag_id')) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', (int) $request->input('tag_id')));
        }

        // Estado en la cartera del cliente (LIQUIDADO, BURO...). Lo escribe la sincronización
        // diaria, no el operador: es un dato de ellos, aparte de nuestras etiquetas.
        if ($request->filled('portfolio_status')) {
            $query->where('portfolio_status', $request->input('portfolio_status'));
        }

        // Filtro "Solo bajas SMS": contactos que no reciben SMS (opt-out / bloqueado / inválido).
        // Eje independiente del status de WhatsApp.
        if ($request->boolean('sms_blocked')) {
            $query->where(function ($q) {
                $q->where('sms_opt_out', true)
                  ->orWhere('sms_blocked', true)
                  ->orWhere('sms_invalid', true);
            });
        }

        // Filtro por entregabilidad, por canal (Enfriamiento WhatsApp != Enfriamiento SMS).
        // Acumulativo: varios estados elegidos se suman (OR).
        DeliverabilityFilter::apply(
            $query,
            DeliverabilityFilter::normalize($request->input('deliverability'))
        );
    }

    /**
     * Los valores de estado de cartera que existen hoy en la base, para armar el desplegable.
     *
     * Se leen de los datos y no de una lista fija a propósito: el catálogo es del cliente y
     * puede crecer sin avisarnos. Si mañana su API manda "REESTRUCTURADO", aparece solo.
     *
     * @return string[]
     */
    public static function portfolioStatuses(): array
    {
        return Contact::query()
            ->whereNotNull('portfolio_status')
            ->where('portfolio_status', '!=', '')
            ->distinct()
            ->orderBy('portfolio_status')
            ->pluck('portfolio_status')
            ->all();
    }
}
