<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Tag;
use App\Services\Contacts\ContactFilters;
use App\Services\Contacts\PhoneListParser;
use App\Services\Tags\TagDeletionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TagController extends Controller
{
    /** Filas por lote al etiquetar en masa. Suficiente para 200k sin inflar la memoria. */
    private const BULK_CHUNK = 2000;

    // GET /api/tags
    // Devuelve SIEMPRE la lista completa (no paginada): la consumen los selectores de
    // etiquetas de Contactos y Campañas, que necesitan todas. El catálogo pagina del lado
    // del navegador; las etiquetas son decenas, no cientos de miles.
    public function index(Request $request): JsonResponse
    {
        $tags = Tag::withCount(['contacts', 'campaigns'])
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = $request->input('q');
                $q->where('name', 'like', "%{$term}%");
            })
            ->orderBy('name')
            ->get();

        return response()->json(['status' => 'ok', 'data' => $tags]);
    }

    // POST /api/tags
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:tags,name',
        ]);

        $tag = Tag::create(['name' => $data['name']]);

        return response()->json(['status' => 'ok', 'data' => $tag], 201);
    }

    // PUT /api/tags/{id}
    // Renombra la etiqueta. El SLUG NO se toca: es la llave estable con la que el importador
    // reconoce una etiqueta existente (ver TagResolver). Si el slug cambiara, el mismo Excel
    // dejaría de reconocerla y crearía una etiqueta duplicada.
    public function update(Request $request, int $id): JsonResponse
    {
        $tag = Tag::find($id);

        if (! $tag) {
            return response()->json(['status' => 'error', 'message' => 'Tag no encontrado.'], 404);
        }

        $data = $request->validate([
            'name' => 'required|string|max:100|unique:tags,name,' . $tag->id,
        ]);

        $tag->update(['name' => $data['name']]);

        return response()->json(['status' => 'ok', 'data' => $tag->fresh()]);
    }

    // GET /api/tags/{id}/usage
    // Qué se lleva por delante borrar la etiqueta. El panel lo pide ANTES de confirmar,
    // para que el operador vea el conteo real en vez de un "¿seguro?" a ciegas.
    public function usage(int $id): JsonResponse
    {
        $tag = Tag::find($id);

        if (! $tag) {
            return response()->json(['status' => 'error', 'message' => 'Tag no encontrado.'], 404);
        }

        $usage = TagDeletionGuard::usage($tag);

        return response()->json([
            'status' => 'ok',
            'data'   => array_merge($usage, [
                'blocked' => TagDeletionGuard::isBlocked($usage),
                'reason'  => TagDeletionGuard::isBlocked($usage)
                    ? TagDeletionGuard::blockedMessage($usage)
                    : null,
            ]),
        ]);
    }

    // DELETE /api/tags/{id}
    public function destroy(int $id): JsonResponse
    {
        $tag = Tag::find($id);

        if (! $tag) {
            return response()->json(['status' => 'error', 'message' => 'Tag no encontrado.'], 404);
        }

        // El bloqueo se revalida aquí, no solo en el endpoint de usage: entre que el
        // operador ve el conteo y confirma pueden pasar minutos, y alguien pudo crear
        // una campaña con esta etiqueta mientras tanto.
        $usage = TagDeletionGuard::usage($tag);

        if (TagDeletionGuard::isBlocked($usage)) {
            return response()->json([
                'status'  => 'error',
                'message' => TagDeletionGuard::blockedMessage($usage),
                'code'    => 'TAG_IN_USE_BY_CAMPAIGN',
                'data'    => $usage,
            ], 422);
        }

        $tag->delete();

        return response()->json([
            'status' => 'ok',
            'data'   => ['contacts_untagged' => $usage['contacts']],
        ]);
    }

    // PUT /api/contacts/{id}/tags
    public function syncContact(Request $request, int $contactId): JsonResponse
    {
        $contact = Contact::find($contactId);

        if (! $contact) {
            return response()->json(['status' => 'error', 'message' => 'Contacto no encontrado.'], 404);
        }

        $request->validate([
            'tag_ids'   => 'array',
            'tag_ids.*' => 'integer|exists:tags,id',
        ]);

        $contact->tags()->sync($request->input('tag_ids', []));

        return response()->json([
            'status' => 'ok',
            'data'   => $contact->tags()->get(['id', 'name', 'slug']),
        ]);
    }

    // POST /api/contacts/tags/bulk-attach
    // Agrega un tag a varios contactos a la vez sin quitar sus tags existentes.
    public function bulkAttach(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contact_ids'   => 'required|array|min:1',
            'contact_ids.*' => 'integer|exists:contacts,id',
            'tag_id'        => 'required|integer|exists:tags,id',
        ]);

        // Una sola inserción, no una consulta por contacto: con 5,000 seleccionados el bucle
        // anterior lanzaba 5,000 consultas y la petición moría por timeout.
        // `insertOrIgnore` sobre la llave primaria (contact_id, tag_id) hace el trabajo de
        // syncWithoutDetaching sin tocar las demás etiquetas del contacto.
        $ids = Contact::whereIn('id', $data['contact_ids'])->pluck('id');

        $attached = $this->attachTagToIds($ids->all(), (int) $data['tag_id']);

        return response()->json([
            'status' => 'ok',
            'data'   => ['attached' => $attached],
        ]);
    }

    /**
     * POST /api/contacts/tags/bulk-attach-filtered
     *
     * Etiqueta TODO lo que cumple los filtros de la pantalla, no solo las filas visibles.
     *
     * Existe porque la selección con casillas solo alcanza lo que está cargado (tope de
     * 5,000 filas). Si el operador filtra por estado de cartera y le salen 40,000 contactos,
     * con casillas tendría que hacerlo en ocho tandas. Aquí manda el filtro, no la lista de
     * IDs, y el servidor recorre todo por lotes.
     */
    public function bulkAttachFiltered(Request $request): JsonResponse
    {
        // OJO con el nombre: `tag_id` ya significa "filtra los que YA tienen esta etiqueta"
        // (ContactFilters::RULES). La etiqueta que se va a PONER va en un campo aparte, si no
        // el filtro se come a la petición y no etiqueta a nadie, en silencio.
        // Con los dos se puede hacer "a todos los que tienen VIP, ponles Renovación".
        $data = $request->validate(array_merge(ContactFilters::RULES, [
            'attach_tag_id' => 'required|integer|exists:tags,id',
            'phones_raw'    => 'nullable|string|max:200000',
        ]));

        $paste = $request->filled('phones_raw')
            ? PhoneListParser::parse((string) $request->input('phones_raw'))
            : null;

        $query = Contact::query();
        ContactFilters::apply($query, $request, $paste);

        $tagId    = (int) $data['attach_tag_id'];
        $attached = 0;

        // `chunkById` mantiene la memoria plana aunque el filtro devuelva 200,000 filas.
        // Solo se piden los ids: no hace falta hidratar el contacto entero para etiquetarlo.
        $query->select('contacts.id')->chunkById(self::BULK_CHUNK, function ($contacts) use ($tagId, &$attached) {
            $attached += $this->attachTagToIds($contacts->pluck('id')->all(), $tagId);
        }, 'contacts.id', 'id');

        return response()->json([
            'status' => 'ok',
            'data'   => ['attached' => $attached],
        ]);
    }

    /**
     * Cuántos contactos cumplen los filtros, para poder avisar antes de etiquetar.
     * POST /api/contacts/tags/bulk-preview
     *
     * Etiquetar 40,000 contactos de un clic no se deshace fácil: el operador tiene que ver
     * el número antes de confirmar.
     */
    public function bulkPreview(Request $request): JsonResponse
    {
        $request->validate(array_merge(ContactFilters::RULES, [
            'phones_raw' => 'nullable|string|max:200000',
        ]));

        $paste = $request->filled('phones_raw')
            ? PhoneListParser::parse((string) $request->input('phones_raw'))
            : null;

        $query = Contact::query();
        ContactFilters::apply($query, $request, $paste);

        return response()->json([
            'status' => 'ok',
            'data'   => ['total' => $query->count()],
        ]);
    }

    /**
     * Inserta los pares (contacto, etiqueta) que falten. Devuelve cuántos se agregaron.
     *
     * @param  int[]  $contactIds
     */
    private function attachTagToIds(array $contactIds, int $tagId): int
    {
        if ($contactIds === []) {
            return 0;
        }

        $pares = array_map(
            fn (int $id) => ['contact_id' => $id, 'tag_id' => $tagId],
            $contactIds
        );

        // insertOrIgnore devuelve las filas realmente insertadas: quien ya tenía la etiqueta
        // no se cuenta dos veces.
        return DB::table('contact_tag')->insertOrIgnore($pares);
    }

    // POST /api/contacts/tags/bulk-detach
    // Quita un tag de varios contactos a la vez sin tocar sus demás tags.
    public function bulkDetach(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contact_ids'   => 'required|array|min:1',
            'contact_ids.*' => 'integer|exists:contacts,id',
            'tag_id'        => 'required|integer|exists:tags,id',
        ]);

        // Igual que attach: una sola consulta en vez de una por contacto.
        $detached = DB::table('contact_tag')
            ->whereIn('contact_id', $data['contact_ids'])
            ->where('tag_id', $data['tag_id'])
            ->delete();

        return response()->json([
            'status' => 'ok',
            'data'   => ['detached' => $detached],
        ]);
    }
}
