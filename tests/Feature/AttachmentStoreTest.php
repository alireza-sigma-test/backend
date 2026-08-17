<?php

use App\Models\Proposal;
use App\Services\AttachmentStore;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Storage;

describe('attachment store', function () {

    // Proposal::factory() creates its author via User::factory()->speaker(),
    // which calls assignRole() — that needs the role to already exist.
    beforeEach(function () {
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    });

    it('stores a PDF in the single-file attachment collection', function () {
        $proposal = Proposal::factory()->create();
        $file = fakePdf('outline.pdf');

        app(AttachmentStore::class)->store($proposal, $file);

        expect($proposal->fresh()->attachment())->not->toBeNull()
            ->and($proposal->fresh()->attachment()->file_name)->toBe('outline.pdf');
    });

    it('replaces rather than accumulates when a second PDF is stored', function () {
        $proposal = Proposal::factory()->create();
        $store = app(AttachmentStore::class);
        $store->store($proposal, fakePdf('first.pdf'));

        $store->store($proposal->fresh(), fakePdf('second.pdf'));

        $proposal = $proposal->fresh();
        expect($proposal->getMedia(Proposal::ATTACHMENT_COLLECTION))->toHaveCount(1)
            ->and($proposal->attachment()->file_name)->toBe('second.pdf');
    });

    it('removes the attachment without touching the proposal', function () {
        $proposal = Proposal::factory()->create(['title' => 'Keep this title']);
        $store = app(AttachmentStore::class);
        $store->store($proposal, fakePdf('outline.pdf'));

        $store->remove($proposal->fresh());

        expect($proposal->fresh()->attachment())->toBeNull()
            ->and($proposal->fresh()->title)->toBe('Keep this title');
    });
});
