<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Tag;
use App\Services\Tags\TagDeletionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
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

        $contacts = Contact::whereIn('id', $data['contact_ids'])->get();

        foreach ($contacts as $contact) {
            // syncWithoutDetaching: agrega el tag sin duplicar ni borrar los demás
            $contact->tags()->syncWithoutDetaching([$data['tag_id']]);
        }

        return response()->json([
            'status' => 'ok',
            'data'   => ['attached' => $contacts->count()],
        ]);
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

        $contacts = Contact::whereIn('id', $data['contact_ids'])->get();

        foreach ($contacts as $contact) {
            $contact->tags()->detach($data['tag_id']);
        }

        return response()->json([
            'status' => 'ok',
            'data'   => ['detached' => $contacts->count()],
        ]);
    }
}
