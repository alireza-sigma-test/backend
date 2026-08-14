<?php

// tests/Feature/Proposals/SubmitProposalTest.php

use App\Models\Tag;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('proposal submission', function () {

    beforeEach(function () {
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    });

    it('starts every proposal as pending', function () {
        // Given
        $dana = User::factory()->speaker()->create();

        // When
        $response = $this->actingAs($dana)->postJson('/api/proposals', [
            'title' => 'Observability at scale',
            'description' => str_repeat('Concrete, numbers-first content. ', 3),
        ]);

        // Then
        $response->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('author.name', $dana->name);
    });

    it('ignores a client-sent status', function () {
        // Given
        $dana = User::factory()->speaker()->create();

        // When
        $response = $this->actingAs($dana)->postJson('/api/proposals', [
            'title' => 'Trying to self-approve',
            'description' => str_repeat('Concrete, numbers-first content. ', 3),
            'status' => 'approved',
        ]);

        // Then
        $response->assertCreated()->assertJsonPath('status', 'pending');
    });

    it('attaches existing tags and creates new ones in one call', function () {
        // Given
        $dana = User::factory()->speaker()->create();
        $tech = Tag::create(['name' => 'Technology']);

        // When
        $response = $this->actingAs($dana)->postJson('/api/proposals', [
            'title' => 'Mixed tag input',
            'description' => str_repeat('Concrete, numbers-first content. ', 3),
            'tags' => [$tech->id, 'Observability'],
        ]);

        // Then
        $response->assertCreated();
        expect(collect($response->json('tags'))->pluck('name')->sort()->values()->all())
            ->toBe(['Observability', 'Technology']);
    });

    it('accepts a PDF up to 4 MB', function () {
        // Given
        $dana = User::factory()->speaker()->create();

        // When
        $response = $this->actingAs($dana)->post('/api/proposals', [
            'title' => 'With slides attached',
            'description' => str_repeat('Concrete, numbers-first content. ', 3),
            'attachment' => fakePdf('outline.pdf')->size(4000),
        ], ['Accept' => 'application/json']);

        // Then
        $response->assertCreated()->assertJsonPath('attachment.filename', 'outline.pdf');
    });

    it('refuses a PDF over 4 MB', function () {
        // Given
        $dana = User::factory()->speaker()->create();

        // When
        $response = $this->actingAs($dana)->post('/api/proposals', [
            'title' => 'Oversized deck',
            'description' => str_repeat('Concrete, numbers-first content. ', 3),
            'attachment' => fakePdf('huge.pdf')->size(5000),
        ], ['Accept' => 'application/json']);

        // Then
        $response->assertStatus(422)->assertJsonValidationErrors('attachment');
    });

    it('refuses a non-PDF attachment', function () {
        // Given
        $dana = User::factory()->speaker()->create();

        // When
        $response = $this->actingAs($dana)->post('/api/proposals', [
            'title' => 'Wrong file type',
            'description' => str_repeat('Concrete, numbers-first content. ', 3),
            // Real non-PDF bytes, so both `mimes:pdf` and `mimetypes:application/pdf`
            // are genuinely exercised rather than trusting a declared mime.
            'attachment' => UploadedFile::fake()->createWithContent('slides.docx', 'PK'.str_repeat('x', 200)),
        ], ['Accept' => 'application/json']);

        // Then
        $response->assertStatus(422)->assertJsonValidationErrors('attachment');
    });

    it('refuses submission from a reviewer', function () {
        // Given
        $maya = User::factory()->reviewer()->create();

        // When
        $response = $this->actingAs($maya)->postJson('/api/proposals', [
            'title' => 'Reviewers may not submit',
            'description' => str_repeat('Concrete, numbers-first content. ', 3),
        ]);

        // Then
        $response->assertForbidden();
    });

    it('rejects a title under 8 characters and a description under 40', function () {
        // Given
        $dana = User::factory()->speaker()->create();

        // When
        $response = $this->actingAs($dana)->postJson('/api/proposals', [
            'title' => 'Short', 'description' => 'Too short.',
        ]);

        // Then
        $response->assertStatus(422)->assertJsonValidationErrors(['title', 'description']);
    });

    it('rejects a description over the max length instead of 500ing on an oversized query', function () {
        // Given — no upper bound previously existed on a TEXT column; a very
        // large payload risked a database-level 500 leaking connection details.
        $dana = User::factory()->speaker()->create();

        // When
        $response = $this->actingAs($dana)->postJson('/api/proposals', [
            'title' => 'A valid title here', 'description' => str_repeat('a', 20001),
        ]);

        // Then
        $response->assertStatus(422)->assertJsonValidationErrors('description');
    });

    // The following close a gap between the brief's shipped resource shape and
    // its given test list: `can`, `average_rating`/`reviews_count`, and the
    // signed attachment URL are all named in the interface but none of the
    // eight tests above assert on them directly.

    it('shows the can flags for the owning speaker of a fresh pending proposal', function () {
        // Given
        $dana = User::factory()->speaker()->create();

        // When
        $response = $this->actingAs($dana)->postJson('/api/proposals', [
            'title' => 'Permission flags check',
            'description' => str_repeat('Concrete, numbers-first content. ', 3),
        ]);

        // Then — owner may still edit a pending proposal; only staff review
        // or decide, so both are false for the speaker who just submitted it.
        $response->assertCreated()
            ->assertJsonPath('can.edit', true)
            ->assertJsonPath('can.review', false)
            ->assertJsonPath('can.change_status', false);
    });

    it('has no rating, no reviews and no attachment on a fresh proposal', function () {
        // Given
        $dana = User::factory()->speaker()->create();

        // When
        $response = $this->actingAs($dana)->postJson('/api/proposals', [
            'title' => 'Fresh proposal fields',
            'description' => str_repeat('Concrete, numbers-first content. ', 3),
        ]);

        // Then
        $response->assertCreated()
            ->assertJsonPath('average_rating', null)
            ->assertJsonPath('reviews_count', 0)
            ->assertJsonPath('attachment', null);
    });

    it('returns a temporary, expiring url for the attachment, not a bare path', function () {
        // Given
        $dana = User::factory()->speaker()->create();

        // When
        $response = $this->actingAs($dana)->post('/api/proposals', [
            'title' => 'Signed url check',
            'description' => str_repeat('Concrete, numbers-first content. ', 3),
            'attachment' => fakePdf('slides.pdf'),
        ], ['Accept' => 'application/json']);

        // Then — Storage::fake() always builds temporary URLs via its own
        // `?expiration=` callback rather than a real HMAC signature (that
        // only fires against the real, non-faked `local` disk's `serve`
        // route). What this proves is that getTemporaryUrl() ran at all:
        // against the `public` disk it throws, because that disk has no
        // `serve` config and cannot build temporary URLs.
        $response->assertCreated();
        expect($response->json('attachment.url'))
            ->toContain('expiration=')
            ->and($response->json('attachment.size_bytes'))->toBeInt()
            ->and($response->json('attachment.mime'))->toBe('application/pdf');
    });

    it('refuses submission without a bearer token', function () {
        // When
        $response = $this->postJson('/api/proposals', [
            'title' => 'Anonymous attempt',
            'description' => str_repeat('Concrete, numbers-first content. ', 3),
        ]);

        // Then
        $response->assertUnauthorized();
    });
});
