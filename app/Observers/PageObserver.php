<?php

namespace App\Observers;

use App\Enums\PageStatus;
use App\Models\Page;
use App\Support\PageCache\PageCacheInvalidator;

/**
 * Two jobs, split by when they have to run.
 *
 * `saving` stamps the publish time, before the write, because it changes what
 * gets written.
 *
 * The rest clear the static cache after the write. `saved` covers create and
 * update, `deleted` and `restored` the rest. All fire after the write commits,
 * so the cache can never be cleared for a change that then rolls back.
 *
 * A change to a Page's *product links* alone does not pass through here — the
 * pivot is synced after the model save — so the Filament resource invalidates
 * again once relationships are written. See PageResource.
 */
class PageObserver
{
    public function __construct(private readonly PageCacheInvalidator $invalidator) {}

    /**
     * `published_at` records when a Page *first* went live, so this only ever
     * fills a blank: a Page pulled back to draft and published again keeps its
     * original date, and a value set by hand survives.
     *
     * It lives here rather than in the Filament form because it is a fact about
     * a Page, not about the admin panel — a Page published from a seeder or
     * tinker gets the same stamp.
     */
    public function saving(Page $page): void
    {
        if ($page->status === PageStatus::Published && $page->published_at === null) {
            $page->published_at = now();
        }
    }

    public function saved(Page $page): void
    {
        $this->invalidator->forgetPage($page);
    }

    public function deleted(Page $page): void
    {
        $this->invalidator->forgetPage($page);
    }

    public function restored(Page $page): void
    {
        $this->invalidator->forgetPage($page);
    }

    public function forceDeleted(Page $page): void
    {
        $this->invalidator->forgetPage($page);
    }
}
