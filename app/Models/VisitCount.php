<?php

namespace App\Models;

use Database\Factories\VisitCountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many times one Page was loaded on one day.
 *
 * The whole of the Visit aggregate: a Page, a date and a count. It is written
 * by `VisitCounter` alone — an increment on the path of a page load, which is
 * the one write a Merchant switches Visit counting on to accept — and read by
 * `ReportingQuery` for the Visits tile and the Page leaderboard's Conversion
 * Rate.
 *
 * It holds no personal data of any kind, which is what lets it be kept
 * forever while the outbox and the Tracking Contexts beside it are pruned.
 *
 * The date is a plain `Y-m-d` string in the Merchant's timezone and carries
 * no cast: a date cast would hand the database a datetime, which one driver
 * truncates and another stores whole, and the two would disagree about which
 * day a row belongs to. Nothing reads it but a range comparison.
 *
 * No timestamps: a row that is incremented on every load would pay for a
 * column nobody reads, and when a count last changed is not a fact about a
 * day's traffic.
 */
#[Fillable([
    'page_id',
    'visited_on',
    'visits',
])]
class VisitCount extends Model
{
    /** @use HasFactory<VisitCountFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * The Page these loads were of. Trashed included: a Page retired after
     * taking Visits still reports what it took. Null once the Page is deleted
     * for good — the count is kept, the attribution is not.
     *
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class)->withTrashed();
    }
}
