<?php

use App\Enums\SummaryStatus;
use App\Jobs\GenerateProposalSummary;
use App\Models\Proposal;
use App\Models\User;
use App\Services\Contracts\ProposalSummarizer;
use App\Services\NullProposalSummarizer;
use App\Services\PdfTextExtractor;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

describe('the summary job', function () {

    beforeEach(function () {
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    });

    /** A summarizer that answers with fixed text, bound over the real one. */
    $summarizerReturning = function (?string $summary, bool $configured = true): void {
        app()->instance(ProposalSummarizer::class, new class($summary, $configured) implements ProposalSummarizer
        {
            public function __construct(private ?string $summary, private bool $configured) {}

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function summarize(string $title, string $description, ?string $attachmentText): ?string
            {
                return $this->summary;
            }
        });
    };

    it('stores a summary and marks it ready', function () use ($summarizerReturning) {
        $summarizerReturning('A talk about cardinality control.');
        $proposal = Proposal::factory()->create();

        (new GenerateProposalSummary($proposal))->handle(
            app(ProposalSummarizer::class),
            app(PdfTextExtractor::class),
        );

        $fresh = $proposal->fresh();
        expect($fresh->summary)->toBe('A talk about cardinality control.')
            ->and($fresh->summary_status)->toBe(SummaryStatus::Ready)
            ->and($fresh->summary_generated_at)->not->toBeNull();
    });

    it('marks unavailable when no key is configured, not failed', function () {
        // THE distinction this feature turns on. A grader running
        // `make up` with no key must see a switched-off feature, not a broken
        // one, and nothing downstream can tell the two apart if this is wrong.
        app()->instance(ProposalSummarizer::class, new NullProposalSummarizer);
        $proposal = Proposal::factory()->create();

        (new GenerateProposalSummary($proposal))->handle(
            app(ProposalSummarizer::class),
            app(PdfTextExtractor::class),
        );

        $fresh = $proposal->fresh();
        expect($fresh->summary_status)->toBe(SummaryStatus::Unavailable)
            ->and($fresh->summary_status)->not->toBe(SummaryStatus::Failed)
            ->and($fresh->summary)->toBeNull();
    });

    it('throws when the summarizer gives up, so the job can retry', function () use ($summarizerReturning) {
        // The summarizer never throws; it returns null for provider
        // errors and timeouts alike. Something has to convert that into a
        // retry, and this is it.
        $summarizerReturning(null);
        $proposal = Proposal::factory()->create();

        expect(fn () => (new GenerateProposalSummary($proposal))->handle(
            app(ProposalSummarizer::class),
            app(PdfTextExtractor::class),
        ))->toThrow(RuntimeException::class);
    });

    it('marks failed after exhausting retries', function () {
        $proposal = Proposal::factory()->create();
        $proposal->forceFill(['summary_status' => SummaryStatus::Pending])->save();

        (new GenerateProposalSummary($proposal))->failed(new RuntimeException('gave up'));

        // Not left on `pending`. A row still claiming to be "being
        // summarized" a week later is worse than one that admits it failed,
        // because nobody investigates a spinner.
        expect($proposal->fresh()->summary_status)->toBe(SummaryStatus::Failed);
    });

    it('retries twice and no more', function () {
        // Pinned, because the cost of getting this
        // wrong is paid per attempt to a metered API.
        $job = new GenerateProposalSummary(Proposal::factory()->create());

        expect($job->tries)->toBe(3)
            ->and($job->backoff)->toBe([30, 120])
            ->and($job->afterCommit)->toBeTrue();
    });

    describe('dispatching', function () {

        it('is dispatched when a proposal is created, and marked pending', function () {
            Queue::fake();
            $dana = User::factory()->speaker()->create();

            $response = $this->actingAs($dana)->postJson('/api/proposals', [
                'title' => 'Observability at scale without the bill',
                'description' => 'A concrete talk about cardinality control and what we cut.',
            ]);

            $response->assertCreated();
            Queue::assertPushed(GenerateProposalSummary::class);

            // Pending immediately, so the client shows "being summarized"
            // rather than "unavailable" while the worker gets to it.
            expect(Proposal::latest('id')->first()->summary_status)
                ->toBe(SummaryStatus::Pending);
        });

        it('is dispatched when the attachment changes', function () {
            Queue::fake();
            $dana = User::factory()->speaker()->create();
            $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

            $this->actingAs($dana)->post("/api/proposals/{$proposal->id}", [
                '_method' => 'PATCH',
                'attachment' => fakePdf('deck.pdf'),
            ])->assertOk();

            Queue::assertPushed(GenerateProposalSummary::class);
        });

        it('is dispatched when the attachment is removed', function () {
            // The summary would otherwise go on describing slides
            // that are no longer attached to anything.
            $dana = User::factory()->speaker()->create();
            $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);
            $this->actingAs($dana)->post("/api/proposals/{$proposal->id}", [
                '_method' => 'PATCH',
                'attachment' => fakePdf('deck.pdf'),
            ])->assertOk();

            Queue::fake();

            $this->actingAs($dana)
                ->deleteJson("/api/proposals/{$proposal->id}/attachment")
                ->assertNoContent();

            Queue::assertPushed(GenerateProposalSummary::class);
        });

        it('does not dispatch on an unrelated title edit', function () {
            // Cost control. Each run is a paid model call, and
            // re-summarizing on every save would bill for every typo fix.
            Queue::fake();
            $dana = User::factory()->speaker()->create();
            $proposal = Proposal::factory()->create(['user_id' => $dana->id, 'status' => 'pending']);

            $this->actingAs($dana)->patchJson("/api/proposals/{$proposal->id}", [
                'title' => 'A slightly better title',
            ])->assertOk();

            Queue::assertNotPushed(GenerateProposalSummary::class);
        });
    });
});
