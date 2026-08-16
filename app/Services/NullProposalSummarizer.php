<?php

// app/Services/NullProposalSummarizer.php

namespace App\Services;

use App\Services\Contracts\ProposalSummarizer;

/**
 * The implementation a deployment with no API key gets.
 *
 * It exists so that "no key" is a binding decision made once, at boot, rather
 * than a null check scattered through the job and the summarizer. Nothing
 * downstream needs to know which implementation it holds — it asks
 * isConfigured() and records `unavailable`.
 *
 * The safety property is worth stating: with this bound, there is no code path
 * that can reach the network at all. A misconfigured deployment cannot make a
 * half-authenticated call; it simply has no client.
 */
final class NullProposalSummarizer implements ProposalSummarizer
{
    public function isConfigured(): bool
    {
        return false;
    }

    public function summarize(string $title, string $description, ?string $attachmentText): ?string
    {
        return null;
    }
}
