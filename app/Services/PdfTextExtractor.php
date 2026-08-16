<?php

// app/Services/PdfTextExtractor.php

namespace App\Services;

use App\Models\Proposal;
use Smalot\PdfParser\Parser;
use Throwable;

final class PdfTextExtractor
{
    /**
     * Hard cap on extracted characters.
     *
     * A 4 MB deck must never become an unbounded prompt: the cost is real
     * money per request, and a slide dump would blow past what is useful to
     * send even where it fits. 12,000 characters is roughly the first several
     * pages of prose — enough to summarize what a talk is about, which is all
     * this feature promises.
     */
    public const MAX_CHARS = 12000;

    public function __construct(private Parser $parser) {}

    /**
     * Text of the proposal's attached PDF, truncated to MAX_CHARS.
     *
     * Returns null — never throws — for every failure mode: no attachment, a
     * file missing from disk, a corrupt or encrypted PDF, a parser that runs
     * out of memory on a pathological document. All of them mean the same
     * thing to the caller ("no extracted text"), and the job that calls this
     * must still summarize the description rather than fail.
     */
    public function extract(Proposal $proposal): ?string
    {
        // Through Media Library, never by guessing a storage path — the disk,
        // the directory scheme and the filename are all its business, and it
        // is the only thing that knows where a given media row actually sits.
        $media = $proposal->getFirstMedia(Proposal::ATTACHMENT_COLLECTION);

        if ($media === null) {
            return null;
        }

        try {
            $text = $this->parser->parseFile($media->getPath())->getText();
        } catch (Throwable) {
            return null;
        }

        // Collapse runs of whitespace before measuring. PDF text extraction
        // emits a lot of layout whitespace, and spending the budget on it
        // would cut real content short.
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, self::MAX_CHARS);
    }
}
