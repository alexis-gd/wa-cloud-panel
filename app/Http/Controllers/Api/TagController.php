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
}
