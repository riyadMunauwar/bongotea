<?php

use App\Enums\PageStatus;
use App\Filament\Resources\Pages\PageResource;
use App\Filament\Resources\Pages\Pages\CreatePage;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Filament\Resources\Pages\Pages\ListPages;
use App\Models\Page;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Authoring is one working area behind `author pages`, with deleting a Page
 * held back on top behind `delete pages` (#158). These assert what a User
 * with, and without, each Permission can reach — never how the gate is wired
 * — through the admin panel over HTTP and Livewire.
 */
it('lets a User holding author pages open the list, create and edit', function () {
    $this->actingAs(User::factory()->authoringPages()->create());

    $page = Page::factory()->create();

    expect(PageResource::canAccess())->toBeTrue();

    $this->get(PageResource::getUrl('index'))->assertOk();
    $this->get(PageResource::getUrl('create'))->assertOk();
    $this->get(PageResource::getUrl('edit', ['record' => $page]))->assertOk();
});

it('refuses every Pages screen to a User without author pages and hides it from the sidebar', function () {
    $this->actingAs(User::factory()->create());

    $page = Page::factory()->create();

    expect(PageResource::canAccess())->toBeFalse();

    $this->get(PageResource::getUrl('index'))->assertForbidden();
    $this->get(PageResource::getUrl('create'))->assertForbidden();
    $this->get(PageResource::getUrl('edit', ['record' => $page]))->assertForbidden();

    $labels = collect(Filament::getCurrentOrDefaultPanel()->getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->map(fn ($item) => $item->getLabel());

    expect($labels)->not->toContain('Pages');
});

it('creates a Page from a title, a slug and a status', function () {
    $this->actingAs(User::factory()->authoringPages()->create());

    Livewire::test(CreatePage::class)
        ->fillForm(['title' => 'Eid offer', 'slug' => 'eid-offer', 'status' => PageStatus::Draft->value])
        ->call('create')
        ->assertHasNoFormErrors();

    $page = Page::sole();

    expect($page->title)->toBe('Eid offer')
        ->and($page->slug)->toBe('eid-offer')
        ->and($page->status)->toBe(PageStatus::Draft);
});

it('refuses a slug that is not flat and lowercase, or that another Page holds', function (string $slug) {
    $this->actingAs(User::factory()->authoringPages()->create());

    Page::factory()->create(['slug' => 'taken']);

    Livewire::test(CreatePage::class)
        ->fillForm(['title' => 'Eid offer', 'slug' => $slug, 'status' => PageStatus::Draft->value])
        ->call('create')
        ->assertHasFormErrors(['slug']);
})->with([
    'upper case' => 'Eid-Offer',
    'nested' => 'offers/eid',
    'spaces' => 'eid offer',
    'already taken' => 'taken',
]);

/**
 * Publishing a Page and pulling it back to draft are a save on the form, so
 * they ride on `author pages` with nothing finer — a User who authors decides
 * what is live.
 */
it('lets author pages publish a Page and pull it back to draft', function () {
    $this->actingAs(User::factory()->authoringPages()->create());

    $page = Page::factory()->create(['slug' => 'promo']);

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
 * Deleting is withheld from a User who only authors: the verb is not drawn
 * on the edit screen or in the table's bulk bar, rather than drawn and
 * refused on use.
 */
it('draws no delete for a User holding only author pages', function () {
    $this->actingAs(User::factory()->authoringPages()->create());

    $page = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertActionHidden('delete');

    Livewire::test(ListPages::class)
        ->assertTableBulkActionHidden('delete');
});

it('draws delete for a User holding delete pages, and deletes the Page', function () {
    $this->actingAs(User::factory()->deletingPages()->create());

    $page = Page::factory()->create();

    Livewire::test(ListPages::class)
        ->assertTableBulkActionVisible('delete');

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertActionVisible('delete')
        ->callAction('delete');

    expect(Page::count())->toBe(0);
});
