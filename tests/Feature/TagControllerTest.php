<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagControllerTest extends TestCase
{
    use RefreshDatabase;

    // ── GET /api/tags ────────────────────────────────────────────────────────

    public function test_index_returns_tags_with_contact_count(): void
    {
        $this->actingAsAdmin();

        Tag::create(['name' => 'VIP', 'slug' => 'vip']);
        Tag::create(['name' => 'Nuevo', 'slug' => 'nuevo']);

        $this->getJson('/api/tags')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'contacts_count']]]);
    }

    // ── POST /api/tags ───────────────────────────────────────────────────────

    public function test_store_creates_tag_and_generates_slug(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/tags', ['name' => 'Zona Norte'])
            ->assertStatus(201)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.name', 'Zona Norte')
            ->assertJsonPath('data.slug', 'zona-norte');
    }

    public function test_store_rejects_duplicate_name(): void
    {
        $this->actingAsAdmin();
        Tag::create(['name' => 'VIP', 'slug' => 'vip']);

        $this->postJson('/api/tags', ['name' => 'VIP'])
            ->assertStatus(422);
    }

    // ── DELETE /api/tags/{id} ────────────────────────────────────────────────

    public function test_destroy_removes_tag(): void
    {
        $this->actingAsAdmin();
        $tag = Tag::create(['name' => 'Temporal', 'slug' => 'temporal']);

        $this->deleteJson("/api/tags/{$tag->id}")->assertOk()->assertJsonPath('status', 'ok');

        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    public function test_destroy_returns_404_for_unknown_tag(): void
    {
        $this->actingAsAdmin();

        $this->deleteJson('/api/tags/9999')->assertNotFound();
    }

    // ── PUT /api/contacts/{id}/tags ──────────────────────────────────────────

    public function test_sync_assigns_tags_to_contact(): void
    {
        $this->actingAsAdmin();

        $contact = Contact::factory()->create();
        $tag1    = Tag::create(['name' => 'A', 'slug' => 'a']);
        $tag2    = Tag::create(['name' => 'B', 'slug' => 'b']);

        $this->putJson("/api/contacts/{$contact->id}/tags", ['tag_ids' => [$tag1->id, $tag2->id]])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonCount(2, 'data');

        $this->assertDatabaseHas('contact_tag', ['contact_id' => $contact->id, 'tag_id' => $tag1->id]);
        $this->assertDatabaseHas('contact_tag', ['contact_id' => $contact->id, 'tag_id' => $tag2->id]);
    }

    public function test_sync_removes_existing_tags_not_in_list(): void
    {
        $this->actingAsAdmin();

        $contact = Contact::factory()->create();
        $tag1    = Tag::create(['name' => 'A', 'slug' => 'a']);
        $tag2    = Tag::create(['name' => 'B', 'slug' => 'b']);

        $contact->tags()->attach([$tag1->id, $tag2->id]);

        // Ahora solo mandamos tag1
        $this->putJson("/api/contacts/{$contact->id}/tags", ['tag_ids' => [$tag1->id]])
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertDatabaseMissing('contact_tag', ['contact_id' => $contact->id, 'tag_id' => $tag2->id]);
    }

    public function test_sync_with_empty_array_removes_all_tags(): void
    {
        $this->actingAsAdmin();

        $contact = Contact::factory()->create();
        $tag     = Tag::create(['name' => 'A', 'slug' => 'a']);
        $contact->tags()->attach($tag->id);

        $this->putJson("/api/contacts/{$contact->id}/tags", ['tag_ids' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseMissing('contact_tag', ['contact_id' => $contact->id]);
    }

    public function test_sync_returns_404_for_unknown_contact(): void
    {
        $this->actingAsAdmin();

        $this->putJson('/api/contacts/9999/tags', ['tag_ids' => []])->assertNotFound();
    }

    // ── GET /api/contacts?tag_id=X ──────────────────────────────────────────

    public function test_contacts_index_filters_by_tag_id(): void
    {
        $this->actingAsAdmin();

        $tag1 = Tag::create(['name' => 'VIP',  'slug' => 'vip']);
        $tag2 = Tag::create(['name' => 'Zona', 'slug' => 'zona']);

        $contactVip  = Contact::factory()->create(['status' => 'active']);
        $contactZona = Contact::factory()->create(['status' => 'active']);

        $contactVip->tags()->attach($tag1->id);
        $contactZona->tags()->attach($tag2->id);

        $res = $this->getJson("/api/contacts?tag_id={$tag1->id}")->assertOk();

        $ids = collect($res->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($contactVip->id));
        $this->assertFalse($ids->contains($contactZona->id));
    }

    // ── Campaign execute filtra por tag ─────────────────────────────────────

    public function test_contacts_index_includes_tags(): void
    {
        $this->actingAsAdmin();

        $contact = Contact::factory()->create(['status' => 'active']);
        $tag     = Tag::create(['name' => 'VIP', 'slug' => 'vip']);
        $contact->tags()->attach($tag->id);

        $this->getJson('/api/contacts')
            ->assertOk()
            ->assertJsonFragment(['name' => 'VIP']);
    }
}
