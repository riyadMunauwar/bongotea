<?php

namespace App\Filament\Resources\Pages\Pages\Concerns;

use App\Filament\Resources\Pages\FrontPageNotice;
use App\Models\Page;
use App\Shortcodes\PageBodyValidator;
use App\Support\PageCache\PageCacheInvalidator;
use Filament\Notifications\Notification;

/**
 * What has to happen after a Page is written, on both create and edit.
 *
 * The observer already invalidates on `saved`, but that fires *before* Filament
 * syncs the product pivot — so changing only a page's linked product would
 * otherwise leave the stale file on disk. Invalidating once more here, after
 * relationships are written, closes that window.
 */
trait FinishesPageSave
{
    protected function invalidateAndWarn(): void
    {
        /** @var Page $page */
        $page = $this->record;

        app(PageCacheInvalidator::class)->forgetPage($page);

        /**
         * Pulling the Front Page back to draft takes the bare domain down to
         * the Placeholder. That is the right outcome and a silent one, so it is
         * said here rather than left for a customer to find.
         */
        FrontPageNotice::sendIfUnseated($page);

        /**
         * Likely typos are surfaced, never enforced: the renderer matches
         * registered names only, so an unrecognised bracketed word is harmless
         * and blocking on one would reject legitimate markup.
         */
        foreach (app(PageBodyValidator::class)->warnings($page->body) as $warning) {
            Notification::make()
                ->title('Possible shortcode typo')
                ->body($warning)
                ->warning()
                ->persistent()
                ->send();
        }
    }
}
