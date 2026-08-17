<?php

namespace App\Jobs;

use App\Enums\SummaryStatus;
use App\Models\Proposal;
use App\Services\Contracts\ProposalSummarizer;
use App\Services\PdfTextExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

final class GenerateProposalSummary implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * Seconds between attempts, widening so a provider having a bad minute gets one.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public Proposal $proposal)
    {
        // The proposal row is written inside a transaction, and a worker will
        // happily outrun an uncommitted write. Called rather than set as a typed
        // property: Queueable already declares `public $afterCommit` untyped, and
        // redeclaring it typed is a fatal at class-composition time.
        $this->afterCommit();
    }

    /**
     * Marking and dispatching live together so no caller can do one without the
     * other — either half alone leaves the client on a permanent wrong state.
     */
    public static function for(Proposal $proposal): void
    {
        $proposal->forceFill(['summary_status' => SummaryStatus::Pending])->save();

        self::dispatch($proposal);
    }

    public function handle(ProposalSummarizer $summarizer, PdfTextExtractor $extractor): void
    {
        // No API key: not an error and not a retry. Asked of the summarizer rather
        // than config so "configured" has one definition — see AppServiceProvider.
        if (! $summarizer->isConfigured()) {
            $this->record(SummaryStatus::Unavailable);

            return;
        }

        $summary = $summarizer->summarize(
            $this->proposal->title,
            $this->proposal->description,
            $extractor->extract($this->proposal),
        );

        if ($summary === null) {
            // The summarizer never throws; it returns null. Throwing is what turns
            // that into a retry, and after $tries into failed().
            throw new RuntimeException(
                "Could not summarize proposal {$this->proposal->id}.",
            );
        }

        $this->record(SummaryStatus::Ready, $summary);
    }

    /** Without this a failed summary sits on `pending` forever. */
    public function failed(?Throwable $exception): void
    {
        $this->record(SummaryStatus::Failed);
    }

    private function record(SummaryStatus $status, ?string $summary = null): void
    {
        // forceFill: none of these columns is fillable, by design.
        $this->proposal->forceFill([
            'summary' => $summary,
            'summary_status' => $status,
            'summary_generated_at' => $status === SummaryStatus::Ready ? now() : null,
        ])->save();
    }
}
