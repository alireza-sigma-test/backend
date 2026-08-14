<?php
// tests/Feature/AttachmentStoreTest.php

use App\Models\Proposal;
use App\Services\AttachmentStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// UploadedFile::fake()->create($name, $kb, $mimeType) only sets a *reported*
// mime type — the underlying temp file is empty. Media Library detects the
// real mime type from file bytes (via finfo) once Storage::fake() writes it
// to disk, so an empty file resolves to application/x-empty and is rejected.
// createWithContent() with a real PDF header makes finfo detection succeed.
function fakePdf(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%fake pdf for testing\n".str_repeat('a', 200));
}

describe('attachment store', function () {

    // Proposal::factory() creates its author via User::factory()->speaker(),
    // which calls assignRole() — that needs the role to already exist.
    beforeEach(function () {
        $this->seed(Database\Seeders\RoleSeeder::class);
        Storage::fake('local');
    });

    it('stores a PDF in the single-file attachment collection', function () {
        // Given
        $proposal = Proposal::factory()->create();
        $file = fakePdf('outline.pdf');

        // When
        app(AttachmentStore::class)->store($proposal, $file);

        // Then
        expect($proposal->fresh()->attachment())->not->toBeNull()
            ->and($proposal->fresh()->attachment()->file_name)->toBe('outline.pdf');
    });

    it('replaces rather than accumulates when a second PDF is stored', function () {
        // Given
        $proposal = Proposal::factory()->create();
        $store = app(AttachmentStore::class);
        $store->store($proposal, fakePdf('first.pdf'));

        // When
        $store->store($proposal->fresh(), fakePdf('second.pdf'));

        // Then
        $proposal = $proposal->fresh();
        expect($proposal->getMedia(Proposal::ATTACHMENT_COLLECTION))->toHaveCount(1)
            ->and($proposal->attachment()->file_name)->toBe('second.pdf');
    });

    it('removes the attachment without touching the proposal', function () {
        // Given
        $proposal = Proposal::factory()->create(['title' => 'Keep this title']);
        $store = app(AttachmentStore::class);
        $store->store($proposal, fakePdf('outline.pdf'));

        // When
        $store->remove($proposal->fresh());

        // Then
        expect($proposal->fresh()->attachment())->toBeNull()
            ->and($proposal->fresh()->title)->toBe('Keep this title');
    });
});
