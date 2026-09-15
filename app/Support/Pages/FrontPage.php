<?php

namespace App\Support\Pages;

use App\Models\Page;
use App\Support\Settings\GeneralSettings;

/**
 * Which Page the Merchant has designated to answer the Site Root.
 *
 * A role a Page plays and never a kind of Page: what comes back here is an
 * ordinary Page that keeps its Slug, its Checkout Config and its Products, and
 * is still reachable at its Slug exactly as it was. Nothing on the Page itself
 * records this — designating one is a decision about the installation, so it
 * lives in Settings and a Page knows nothing about being one.
 *
 * The two questions are deliberately different. `designated()` asks *what
 * should the Site Root serve*, and answers with nothing the moment the Page
 * cannot be served — a draft, a soft delete, an id no Page has — so the Site
 * Root falls back to the Placeholder. `isDesignated()` asks *is this the Page
 * the Merchant chose*, and answers yes whatever state it is in, because the
 * invalidator has to forget the Site Root's cached bytes at precisely the
 * moments `designated()` has already stopped answering.
 *
 * The setting is read on a cache miss and on an admin write, never on a served
 * request: the Site Root is answered from disk by existence alone, and nothing
 * about how this is stored can cost a visitor anything.
 */
class FrontPage
{
    /**
     * The group is resolved per call and never held.
     *
     * `Illuminate\Routing\Route::getController()` caches the controller it
     * builds *on the Route object*, and the route collection outlives a
     * request. So a `FrontPage` taken through `LandingPageController`'s
     * constructor is built once and reused, and a `GeneralSettings` held on it
     * would be frozen at whatever the first request saw — `save()` replaces the
     * container's instance, but nothing reaches one already captured in a
     * constructor.
     *
     * In production that is invisible, because each request is a new process;
     * it would be a live bug the day this ran under Octane, and it is a real
     * one in a test that changes the Setting between two requests. Resolving
     * here costs nothing either way: the group is a per-request singleton, so
     * the row is read once however many times this is asked.
     *
     * The same hazard one layer up is why `.ai/rules/settings.md` insists a
     * Filament schema reads a Setting inside a closure rather than as a plain
     * argument.
     */
    private function settings(): GeneralSettings
    {
        return app(GeneralSettings::class);
    }

    /**
     * The Page the Site Root should serve, or null for the Placeholder.
     *
     * Published only, and never a soft-deleted one. A Front Page pulled back to
     * draft is remembered rather than cleared, so publishing it again restores
     * the Site Root without the Merchant returning to Settings.
     */
    public function designated(): ?Page
    {
        if ($this->settings()->frontPageId <= 0) {
            return null;
        }

        return Page::query()->published()->find($this->settings()->frontPageId);
    }

    /**
     * Whether this Page is the one the Merchant designated, published or not.
     */
    public function isDesignated(Page $page): bool
    {
        return $this->settings()->frontPageId > 0
            && $this->settings()->frontPageId === $page->getKey();
    }
}
