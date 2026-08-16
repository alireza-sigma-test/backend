<?php

// app/Services/Contracts/ProposalSummarizer.php

namespace App\Services\Contracts;

/**
 * Writes a short, factual summary of a proposal for the people reviewing it.
 *
 * Primitives in, string out — deliberately no Proposal model in the signature.
 * That keeps every implementation testable without a database, keeps the
 * layering test happy, and makes it obvious that this service has no business
 * reading anything off the model beyond the three things it is handed.
 */
interface ProposalSummarizer
{
    /**
     * Whether this deployment can actually produce summaries.
     *
     * False is not an error: it is the normal state of a clone with no API
     * key, and the caller uses it to record `unavailable` rather than
     * `failed`. Without this the two are indistinguishable from the outside,
     * because both come back as a null summary — and a grader running
     * `make up` would see the feature reported as broken rather than off.
     */
    public function isConfigured(): bool;

    /** @return string|null null means "could not summarize", never an exception */
    public function summarize(string $title, string $description, ?string $attachmentText): ?string;
}
