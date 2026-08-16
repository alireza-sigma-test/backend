<?php

namespace App\Enums;

/**
 * Where a proposal's AI summary stands.
 *
 * `Failed` and `Unavailable` are deliberately distinct, and the distinction is
 * the whole no-key story: failed means something went wrong and is worth
 * investigating, unavailable means this deployment simply has no API key
 * configured. Unavailable is the normal state for anyone running `make up`
 * from a clean clone, and it must not read as breakage anywhere — not in the
 * database, not in the API, and not on screen.
 */
enum SummaryStatus: string
{
    case Pending = 'pending';           // queued, not yet run
    case Ready = 'ready';               // a summary exists
    case Failed = 'failed';             // ran and gave up after retries
    case Unavailable = 'unavailable';   // no API key configured — not an error

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
