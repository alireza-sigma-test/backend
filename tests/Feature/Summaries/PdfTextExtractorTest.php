<?php

use App\Models\Proposal;
use App\Services\PdfTextExtractor;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('pdf text extraction', function () {

    beforeEach(function () {
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    });

    /**
     * The fixtures are real, valid PDFs, unlike the `fakePdf()` helper other suites use
     * — a PDF header in front of arbitrary bytes satisfies a mime sniff but not a
     * parser. Copied to a temp path first because addMedia() MOVES the file it is
     * given, which would otherwise delete the fixture from the repository.
     */
    $attach = function (Proposal $proposal, string $fixture): Proposal {
        $temp = tempnam(sys_get_temp_dir(), 'fixture').'.pdf';
        copy(base_path("tests/fixtures/{$fixture}"), $temp);

        $proposal->addMedia($temp)
            ->usingFileName($fixture)
            ->toMediaCollection(Proposal::ATTACHMENT_COLLECTION);

        return $proposal->fresh();
    };

    it('extracts text from a pdf', function () use ($attach) {
        $proposal = $attach(Proposal::factory()->create(), 'proposal.pdf');

        $text = app(PdfTextExtractor::class)->extract($proposal);

        // A sentinel string that exists nowhere but inside the PDF, so
        // this cannot pass on a filename or a stray buffer.
        expect($text)->toContain('SENTINEL-EXTRACTED-OK')
            ->and($text)->toContain('Observability at scale without the bill');
    });

    it('truncates to the character budget', function () use ($attach) {
        // The long fixture parses to ~26,800 characters, comfortably
        // past the budget, so this measures a real cap rather than a short file.
        $proposal = $attach(Proposal::factory()->create(), 'long.pdf');

        $text = app(PdfTextExtractor::class)->extract($proposal);

        expect(mb_strlen($text))->toBe(PdfTextExtractor::MAX_CHARS);
    });

    it('returns null for a proposal with no attachment', function () {
        // Not an exception. Most proposals have no PDF, and the job
        // must summarize their description alone rather than fail.
        $proposal = Proposal::factory()->create();

        expect(app(PdfTextExtractor::class)->extract($proposal))->toBeNull();
    });

    it('returns null rather than throwing on an unreadable pdf', function () {
        // A file that claims to be a PDF and is not. A corrupt upload
        // is a normal event, not an exceptional one: it must degrade to "no
        // extracted text" and let the summary run on the description.
        $proposal = Proposal::factory()->create();
        $proposal->addMedia(UploadedFile::fake()->createWithContent(
            'corrupt.pdf',
            "%PDF-1.4\nthis is not actually a pdf at all\n",
        ))->toMediaCollection(Proposal::ATTACHMENT_COLLECTION);

        expect(app(PdfTextExtractor::class)->extract($proposal->fresh()))->toBeNull();
    });

    it('returns null when the stored file is missing from disk', function () use ($attach) {
        // A media row whose file is gone. Unreachable in normal
        // operation, but the job runs minutes after the upload and reads from
        // a separate process; a missing file must not crash it.
        $proposal = $attach(Proposal::factory()->create(), 'proposal.pdf');
        @unlink($proposal->getFirstMedia(Proposal::ATTACHMENT_COLLECTION)->getPath());

        expect(app(PdfTextExtractor::class)->extract($proposal))->toBeNull();
    });
});
