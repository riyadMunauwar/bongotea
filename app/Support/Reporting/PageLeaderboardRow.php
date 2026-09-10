<?php

namespace App\Support\Reporting;

use App\Models\Page;
use Carbon\CarbonInterface;

/**
 * One line of the Page leaderboard: a Page and what its Orders in the
 * window came to, by the glossary's rules. The Page is carried whole,
 * trashed or not, so the widget can link to its edit screen and say it is
 * deleted; an Order whose Page was deleted for good has only its recorded
 * slug left, and the row carries that instead with no Page at all.
 *
 * Money is integer minor units; the two ratios are the same methods the
 * Overview's figures use, so a rate is never stored beside the counts it
 * disagrees with.
 */
final readonly class PageLeaderboardRow
{
    use HasSalesRatios;

    public function __construct(
        /** The Page the Orders were captured on, trashed included; null once it is deleted for good. */
        public ?Page $page,
        /** The slug the Orders recorded at Capture: the attribution that survives a hard delete. */
        public string $slug,
        /** Every Order captured on the Page in the window, whatever became of it. */
        public int $orders,
        /** The totals, Delivery Charge included, of the Orders not Cancelled. */
        public int $sales,
        public int $cancelled,
    ) {}

    /**
     * What the row is called: the Page's title, or the recorded slug when
     * there is no Page left to ask.
     */
    public function title(): string
    {
        return $this->page?->title ?? $this->slug;
    }

    /**
     * Whether the Page has been soft-deleted since it took these Orders.
     * A hard-deleted Page is not "deleted" in this sense; it is gone, and
     * the row says so by having no Page.
     */
    public function isDeleted(): bool
    {
        return $this->page?->trashed() ?? false;
    }

    /**
     * When the Page went live; null for a draft or for a Page that is gone.
     */
    public function wentLiveAt(): ?CarbonInterface
    {
        return $this->page?->published_at;
    }
}
