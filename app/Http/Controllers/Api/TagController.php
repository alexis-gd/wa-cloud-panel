<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    // GET /api/tags
    public function index(): JsonResponse
    {
        $tags = Tag::withCount('contacts')->orderBy('name')->get();

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

    // DELETE /api/tags/{id}
    public function destroy(int $id): JsonResponse
    {
        $tag = Tag::find($id);

        if (! $tag) {
            return response()->json(['status' => 'error', 'message' => 'Tag no encontrado.'], 404);
        }

        $tag->delete();

        return response()->json(['status' => 'ok']);
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
