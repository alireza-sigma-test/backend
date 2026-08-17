<?php

namespace App\Services;

use App\Models\Proposal;
use Smalot\PdfParser\Parser;
use Throwable;

final class PdfTextExtractor
{
    /** Keeps a 4 MB deck from becoming an unbounded, billable prompt. */
    public const MAX_CHARS = 12000;

    public function __construct(private Parser $parser) {}

    /**
     * Text of the proposal's attached PDF, truncated to MAX_CHARS. Returns null and
     * never throws for every failure mode — missing, corrupt, encrypted, unparseable
     * — because the caller must still summarize the description.
     */
    public function extract(Proposal $proposal): ?string
    {
        $media = $proposal->getFirstMedia(Proposal::ATTACHMENT_COLLECTION);

        if ($media === null) {
            return null;
        }

        try {
            $text = $this->parser->parseFile($media->getPath())->getText();
        } catch (Throwable) {
            return null;
        }

        // Collapsed before measuring, so the character budget is not spent on the
        // layout whitespace PDF extraction emits.
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, self::MAX_CHARS);
    }
}
