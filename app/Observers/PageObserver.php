<?php

namespace App\Observers;

use App\Models\Page;
use App\Support\PageCache\PageCacheInvalidator;

/**
 * `saved` covers create and update, `deleted` and `restored` the rest. All fire
 * after the write commits, so the cache can never be cleared for a change that
 * then rolls back.
 *
 * A change to a Page's *product links* alone does not pass through here — the
 * pivot is synced after the model save — so the Filament resource invalidates
 * again once relationships are written. See PageResource.
 */
class PageObserver
{
    public function __construct(private readonly PageCacheInvalidator $invalidator) {}

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
