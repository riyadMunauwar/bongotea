<?php

namespace App\Filament\Resources\Pages;

use App\Models\Page;
use App\Support\Pages\FrontPage;
use Filament\Notifications\Notification;

/**
 * Tells a Merchant, at the moment they do it, that they have just taken their
 * own bare domain down to the Placeholder.
 *
 * Unpublishing or binning the Front Page is allowed, and deliberately so: the
 * alternatives were refusing the action — which makes a Settings screen hold a
 * Page hostage, so taking anything down means knowing where the hostage is
 * kept — and answering `/` with a 404, which makes the Site Root the one
 * address on the site that can break. The Placeholder is the recoverable
 * answer, and the designation is remembered, so publishing the Page again
 * restores the Site Root with no second trip to Settings.
 *
 * What those two options were really protecting is that the Merchant finds out.
 * That is all this does, and it is why it is persistent: a toast that fades
 * while they are reading the row it is about has not told them anything, and
 * the next person to notice would be a customer.
 *
 * **It is called from five places and is deliberately not a `PageObserver`
 * hook.** Gathering it there would cover every path at once, including a sixth
 * nobody has written yet — but a Filament notification is a message to the
 * person on the screen, and a model observer fires with no screen at all: a
 * seeder, a factory, `php artisan tinker`, a queued job. `Notification::send()`
 * with no session behind it is at best discarded and at worst a write to a
 * session store that is not there. The Page cache *is* invalidated from the
 * observer, because that is a fact about the installation; who gets told is a
 * fact about the request, so it stays where the requests are.
 *
 * The cost is real and worth naming: a new way to unpublish or bin a Page will
 * not warn anybody unless whoever writes it calls this. That is what the Front
 * Page badge on the Pages list is there to soften — a Merchant who can see
 * which row it is does not depend on being told afterwards.
 */
class FrontPageNotice
{
    /**
     * Warn if this Page is the designated Front Page and can no longer answer.
     *
     * Both halves are needed. `isDesignated()` alone would fire on any save of
     * the Front Page, including the ordinary edits that are the whole point of
     * having one; `designated()` returning null alone would fire on every save
     * of every *other* Page for as long as the Front Page sat in draft.
     * Together they are exactly the transition worth a sentence.
     */
    public static function sendIfUnseated(Page $page): void
    {
        $frontPage = app(FrontPage::class);

        if (! $frontPage->isDesignated($page) || $frontPage->designated() !== null) {
            return;
        }

        Notification::make()
            ->title('Your front page is no longer being served')
            ->body('This was the page answering your bare domain, which now shows the GioPages placeholder. Publish it again, or choose another under Settings → General.')
            ->warning()
            ->persistent()
            ->send();
    }
}
