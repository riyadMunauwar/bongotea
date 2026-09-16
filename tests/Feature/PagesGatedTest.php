<?php

use App\Enums\PageStatus;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings\GeneralSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Authoring is one working area behind `author pages`, with binning a Page held
 * back on top behind `delete pages` (#158). These assert what a User with, and
 * without, each Permission can reach — never how the gate is wired — through the
 * admin panel over HTTP and Livewire, which is the primary seam the spec names
 * for a working-area gate.
 *
 * A Slug is claimed for good and may be baked into ad creatives that outlive the
 * Page, and deleting one can also unseat the Front Page, so `delete pages` is a
 * Permission the person writing headlines is not given by default.
 */
it('lets a User holding author pages open the list, create, edit, preview and Page Report', function () {
    $this->actingAs(User::factory()->authoringPages()->create());

    $page = Page::factory()->create();

    expect(PageResource::canAccess())->toBeTrue();

    $this->get(PageResource::getUrl('index'))->assertOk();
    $this->get(PageResource::getUrl('create'))->assertOk();
    $this->get(PageResource::getUrl('edit', ['record' => $page]))->assertOk();
    $this->get(PageResource::getUrl('report', ['record' => $page]))->assertOk();
    $this->get(route('pages.preview', $page))->assertOk();
});

it('refuses every Pages screen to a User without author pages and hides it from the sidebar', function () {
    $this->actingAs(User::factory()->create());

    $page = Page::factory()->create();

    expect(PageResource::canAccess())->toBeFalse();

    $this->get(PageResource::getUrl('index'))->assertForbidden();
    $this->get(PageResource::getUrl('create'))->assertForbidden();
    $this->get(PageResource::getUrl('edit', ['record' => $page]))->assertForbidden();
    $this->get(PageResource::getUrl('report', ['record' => $page]))->assertForbidden();
    $this->get(route('pages.preview', $page))->assertForbidden();

    $labels = collect(Filament::getCurrentOrDefaultPanel()->getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->map(fn ($item) => $item->getLabel());

    expect($labels)->not->toContain('Pages');
});

/**
 * Publishing a Page and pulling it back to draft are a save on the form, so
 * they ride on `author pages` with nothing finer — a User who authors decides
 * what is live.
 */
it('lets author pages publish a Page and pull it back to draft', function () {
    $this->actingAs(User::factory()->authoringPages()->create());

    $product = Product::factory()->create();
    $page = Page::factory()->withBody('<main>[checkout]</main>')->create(['slug' => 'promo']);
    $page->products()->attach($product);

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['status' => PageStatus::Published->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh()->status)->toBe(PageStatus::Published);

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->fillForm(['status' => PageStatus::Draft->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh()->status)->toBe(PageStatus::Draft);
});

/**
 * Deleting, force-deleting and restoring are withheld from a User who only
 * authors: the verbs are not drawn on the edit screen or in the table's bulk
 * bar, rather than drawn and refused on use.
 */
it('draws no delete, force-delete or restore for a User holding only author pages', function () {
    $this->actingAs(User::factory()->authoringPages()->create());

    $live = Page::factory()->create();
    $binned = Page::factory()->trashed()->create();

    Livewire::test(EditPage::class, ['record' => $live->getKey()])
        ->assertActionHidden('delete');

    Livewire::test(EditPage::class, ['record' => $binned->getKey()])
        ->assertActionHidden('forceDelete')
        ->assertActionHidden('restore');

    /**
     * The bulk verbs too: `delete` in the default view, and `forceDelete` and
     * `restore` with the bin shown — where Filament's own filter would draw
     * them — so the gate is what withholds them, not the missing filter.
     */
    Livewire::test(ListPages::class)
        ->assertTableBulkActionHidden('delete')
        ->filterTable('trashed', ['value' => true])
        ->assertTableBulkActionHidden('forceDelete')
        ->assertTableBulkActionHidden('restore');
});

/**
 * A User holding `delete pages` — which carries `author pages` with it, since
 * the verbs are offered only from a screen a User who authors can reach — is
 * given all three, in the same places.
 */
it('draws delete, force-delete and restore for a User holding delete pages', function () {
    $this->actingAs(User::factory()->deletingPages()->create());

    $live = Page::factory()->create();
    $binned = Page::factory()->trashed()->create();

    Livewire::test(EditPage::class, ['record' => $live->getKey()])
        ->assertActionVisible('delete');

    Livewire::test(EditPage::class, ['record' => $binned->getKey()])
        ->assertActionVisible('forceDelete')
        ->assertActionVisible('restore');

    /** All three bulk verbs drawn, `forceDelete` and `restore` once the bin is shown. */
    Livewire::test(ListPages::class)
        ->assertTableBulkActionVisible('delete')
        ->filterTable('trashed', ['value' => true])
        ->assertTableBulkActionVisible('forceDelete')
        ->assertTableBulkActionVisible('restore');
});

/**
 * The gate withholds the Action; it never weakens what deleting does. Binning
 * the Front Page still unseats it and still warns the Merchant, so the one
 * protection that survives — being told now rather than by a customer — is not
 * lost to the person who was trusted with `delete pages`.
 */
it('still warns the Merchant when a delete-pages User bins the Front Page', function () {
    $this->actingAs(User::factory()->deletingPages()->create());

    $page = Page::factory()->published()->create();

    app(GeneralSettings::class)->with(['frontPageId' => $page->getKey()])->save();

    Livewire::test(EditPage::class, ['record' => $page->getRouteKey()])
        ->callAction('delete')
        ->assertNotified('Your front page is no longer being served');

    expect($page->refresh()->trashed())->toBeTrue();
});
