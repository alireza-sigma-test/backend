<?php

// app/Services/ClaudeProposalSummarizer.php

namespace App\Services;

use App\Ai\Agents\ProposalSummaryAgent;
use App\Services\Contracts\ProposalSummarizer;
use Laravel\Ai\Enums\Lab;
use Throwable;

/**
 * Summarizes through Claude, via the first-party Laravel AI SDK.
 *
 * One call, an explicit timeout, and **no retry here**: the job owns retrying
 * (GenerateProposalSummary, `$tries = 3`). Retrying in both places multiplies
 * attempts silently — three job attempts against three client retries is nine
 * calls to a paid API for one summary, and nothing in the logs says so.
 *
 * Returns null on every failure rather than throwing, per the contract. The
 * caller cannot do anything useful with a provider exception except record
 * that no summary was produced, and letting it escape would put vendor
 * exception types in the job's signature.
 */
final class ClaudeProposalSummarizer implements ProposalSummarizer
{
    public function isConfigured(): bool
    {
        // If this is bound at all a key was present at boot (see
        // AppServiceProvider). Re-reading config rather than returning a bare
        // `true` keeps the answer honest if the binding is ever overridden in
        // a test or a tinker session.
        return filled(config('ai.providers.anthropic.key'));
    }

    public function summarize(string $title, string $description, ?string $attachmentText): ?string
    {
        try {
            $response = (new ProposalSummaryAgent)->prompt(
                $this->compose($title, $description, $attachmentText),
                provider: Lab::Anthropic,
                model: config('ai.summary.model'),
                timeout: config('ai.summary.timeout'),
            );
        } catch (Throwable $e) {
            // Reported, not swallowed: the caller only needs "no summary", but
            // an operator investigating a run of `failed` rows needs the
            // provider's actual error.
            report($e);

            return null;
        }

        $summary = trim($response->text);

        return $summary === '' ? null : $summary;
    }

    /**
     * The user message.
     *
     * The delimiters are load-bearing, not formatting. Extracted PDF text is
     * untrusted input — a speaker controls it and benefits from a flattering
     * summary — so it goes in a block the system prompt has already named as
     * material to summarize rather than instructions to follow.
     */
    private function compose(string $title, string $description, ?string $attachmentText): string
    {
        $prompt = "<PROPOSAL>\nTitle: {$title}\n\nDescription:\n{$description}\n</PROPOSAL>";

        if ($attachmentText !== null && trim($attachmentText) !== '') {
            // Already truncated to PdfTextExtractor::MAX_CHARS by the caller.
            $prompt .= "\n\n<ATTACHMENT>\n{$attachmentText}\n</ATTACHMENT>";
        }

        return $prompt;
    }
}
