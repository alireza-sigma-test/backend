<?php

// app/Jobs/GenerateProposalSummary.php

namespace App\Jobs;

use App\Enums\SummaryStatus;
use App\Models\Proposal;
use App\Services\Contracts\ProposalSummarizer;
use App\Services\PdfTextExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Generates a proposal's AI summary out of band.
 *
 * Queued, never inline: PDF extraction plus a model call is far too slow to
 * sit inside the POST that creates the proposal, and a speaker should not wait
 * on a third-party API to find out whether their submission saved.
 */
final class GenerateProposalSummary implements ShouldQueue
{
    use Queueable;

    /**
     * Two retries after the first attempt. Provider errors are usually
     * transient (a 529, a timeout); permanent ones are not worth paying for
     * more than three times.
     */
    public int $tries = 3;

    /**
     * Seconds between attempts. Widening rather than fixed, so a provider
     * having a bad minute gets a minute to recover.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function __construct(public Proposal $proposal)
    {
        // The row is written inside the transaction that creates or updates
        // the proposal, so the job must not start before that commits — a
        // worker is a separate process and will happily outrun an uncommitted
        // write, then find no such proposal.
        //
        // Set here rather than as a `public bool $afterCommit = true` property:
        // Illuminate\Bus\Queueable already declares `public $afterCommit` with
        // no type, and redeclaring it typed is a fatal "definition differs and
        // is considered incompatible" at class-composition time. Pest reports
        // that as a silent exit 2 with no output at all.
        $this->afterCommit();
    }

    /**
     * Mark a proposal as queued and dispatch.
     *
     * Both halves live here so no caller can do one without the other. A
     * proposal that is queued but not marked shows the client "unavailable"
     * until the job lands; one marked but not queued sits on "being
     * summarized" forever.
     */
    public static function for(Proposal $proposal): void
    {
        $proposal->forceFill(['summary_status' => SummaryStatus::Pending])->save();

        self::dispatch($proposal);
    }

    public function handle(ProposalSummarizer $summarizer, PdfTextExtractor $extractor): void
    {
        // Not an error, and not a retry: this deployment has no API key. Ask
        // the summarizer rather than the config so there is one definition of
        // "configured" — see AppServiceProvider's binding.
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
            // The summarizer never throws — it returns null for provider
            // errors, timeouts and empty generations alike. Throwing here is
            // what converts that into a retry, and after $tries into failed().
            throw new RuntimeException(
                "Could not summarize proposal {$this->proposal->id}.",
            );
        }

        $this->record(SummaryStatus::Ready, $summary);
    }

    /**
     * Record the give-up.
     *
     * Without this a proposal whose summary failed sits on `pending` forever,
     * and a row that still claims to be "being summarized" a week later is
     * worse than one that admits it failed — nobody investigates a spinner.
     */
    public function failed(?Throwable $exception): void
    {
        $this->record(SummaryStatus::Failed);
    }

    private function record(SummaryStatus $status, ?string $summary = null): void
    {
        // forceFill, because none of these columns is fillable — they are
        // written here and nowhere else, never from a request.
        $this->proposal->forceFill([
            'summary' => $summary,
            'summary_status' => $status,
            'summary_generated_at' => $status === SummaryStatus::Ready ? now() : null,
        ])->save();
    }
}
