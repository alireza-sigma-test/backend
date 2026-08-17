<?php

namespace App\Services;

use App\Ai\Agents\ProposalSummaryAgent;
use App\Services\Contracts\ProposalSummarizer;
use Laravel\Ai\Enums\Lab;
use Throwable;

/**
 * No retry here — GenerateProposalSummary owns it. Retrying in both places would
 * silently multiply calls to a paid API. Returns null on failure rather than
 * throwing, keeping vendor exception types out of the job's signature.
 */
final class ClaudeProposalSummarizer implements ProposalSummarizer
{
    public function isConfigured(): bool
    {
        // Re-read rather than a bare `true`, so the answer stays honest if the
        // binding is overridden in a test.
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
            // Reported, not swallowed: an operator investigating a run of `failed`
            // rows needs the provider's actual error.
            report($e);

            return null;
        }

        $summary = trim($response->text);

        return $summary === '' ? null : $summary;
    }

    /**
     * The delimiters are load-bearing, not formatting: PDF text is speaker-controlled
     * input, so it goes in a block the system prompt names as material to summarize
     * rather than instructions to follow.
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
