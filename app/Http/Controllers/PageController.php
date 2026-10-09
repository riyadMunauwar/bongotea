<?php

namespace App\Http\Controllers;

use App\Enums\SystemPage;
use App\Models\Page;
use App\Support\Pages\PageBlocks;
use App\Support\Settings\StoreSettings;
use App\Support\Storefront\Seo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pages built from Blocks: Home at the bare domain, every other Page at its
 * slug. Only a published Page is reachable; a draft, like a slug nobody
 * holds, is a 404.
 */
class PageController extends Controller
{
    public function home(Request $request, StoreSettings $store): Response
    {
        $page = Page::query()->system(SystemPage::Home)->published()->firstOrFail();

        return $this->render($page, $store, $request);
    }

    /**
     * Home asked for by its slug is sent to the bare domain, so it has one
     * address.
     */
    public function show(Request $request, StoreSettings $store, string $slug): Response|RedirectResponse
    {
        $page = Page::query()->published()->where('slug', $slug)->firstOrFail();

        if ($page->isHome()) {
            return redirect()->route('home', status: 301);
        }

        return $this->render($page, $store, $request);
    }

    private function render(Page $page, StoreSettings $store, Request $request): Response
    {
        return Inertia::render('page', [
            'page' => [
                'title' => $page->title,
                'blocks' => PageBlocks::forStorefront($page),
            ],
            'seo' => Seo::forPage($page, $store, $request)->toArray(),
        ]);
    }
}
