<?php

namespace App\Services\Contracts;

/**
 * Primitives in, string out — no Proposal model in the signature, so every
 * implementation is testable without a database.
 */
interface ProposalSummarizer
{
    /**
     * False is not an error — it is the normal state of a clone with no API key, and
     * it is what lets the caller record `unavailable` rather than `failed`.
     */
    public function isConfigured(): bool;

    /** @return string|null null means "could not summarize", never an exception */
    public function summarize(string $title, string $description, ?string $attachmentText): ?string;
}
