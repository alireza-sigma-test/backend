<?php

namespace App\Enums;

/**
 * `Failed` and `Unavailable` are deliberately distinct: failed is worth
 * investigating, unavailable just means no API key. Unavailable is the normal state
 * of a clean clone and must never read as breakage.
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
