<?php

namespace App\Services;

use App\Services\Contracts\ProposalSummarizer;

/**
 * Bound when there is no API key, making "no key" one decision at boot rather than a
 * null check in every caller. With this bound no code path can reach the network.
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
