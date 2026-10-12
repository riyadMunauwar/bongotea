<?php

use App\Enums\PageStatus;
use App\Enums\SystemPage;
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

/*
|--------------------------------------------------------------------------
| Blocks, reserved slugs and System Pages
|--------------------------------------------------------------------------
*/

it('builds a Page from Blocks, with an anchor and a screen switch, and its SEO fields', function () {
    $this->actingAs(User::factory()->authoringPages()->create());

    Livewire::test(CreatePage::class)
        ->fillForm([
            'title' => 'About',
            'slug' => 'about',
            'status' => PageStatus::Published->value,
            'blocks' => [
                ['type' => 'cta_band', 'data' => [
                    'heading' => 'আপনার দোকানের জন্য চা লাগবে?',
                    'primary_label' => 'বাল্ক অর্ডার করুন',
                    'primary_link' => '/bulk-order',
                    'anchor' => 'order-now',
                    'hide_on_desktop' => true,
                ]],
            ],
            'seo_title' => 'আমাদের সম্পর্কে',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $page = Page::sole();

    expect($page->seo_title)->toBe('আমাদের সম্পর্কে')
        ->and($page->blocks)->toHaveCount(1)
        ->and($page->blocks[0]['type'])->toBe('cta_band')
        ->and($page->blocks[0]['data'])->toMatchArray([
            'heading' => 'আপনার দোকানের জন্য চা লাগবে?',
            'primary_link' => '/bulk-order',
            'anchor' => 'order-now',
            'hide_on_desktop' => true,
        ]);
});

it('opens and saves chips stored as bare words as unlinked chips', function () {
    $this->actingAs(User::factory()->authoringPages()->create());

    $page = Page::factory()->withBlocks([
        ['type' => 'chips_row', 'data' => ['heading' => 'যাঁদের জন্য আমরা', 'chips' => ['চায়ের দোকান', 'হোটেল']]],
    ])->create(['slug' => 'about']);

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(array_column($page->refresh()->blocks[0]['data']['chips'], 'label'))->toBe(['চায়ের দোকান', 'হোটেল']);
});

it('refuses a Block anchor or button link a Menu could not hold', function (array $data) {
    $this->actingAs(User::factory()->authoringPages()->create());

    Livewire::test(CreatePage::class)
        ->fillForm([
            'title' => 'About',
            'slug' => 'about',
            'status' => PageStatus::Draft->value,
            'blocks' => [['type' => 'cta_band', 'data' => ['heading' => 'চা লাগবে?', ...$data]]],
        ])
        ->call('create')
        ->assertHasErrors();

    expect(Page::count())->toBe(0);
})->with([
    'anchor with a hash' => [['anchor' => '#order']],
    'anchor with spaces' => [['anchor' => 'order now']],
    'script link' => [['primary_label' => 'চাপুন', 'primary_link' => 'javascript:alert(1)']],
]);

it('refuses a slug the store answers at itself', function (string $slug) {
    $this->actingAs(User::factory()->authoringPages()->create());

    Livewire::test(CreatePage::class)
        ->fillForm(['title' => 'Taken', 'slug' => $slug, 'status' => PageStatus::Draft->value])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'not_in']);

    expect(Page::count())->toBe(0);
})->with(['tea', 'category', 'search', 'checkout', 'order', 'pay', 'admin']);

it('refuses a reserved slug however the Page is saved', function () {
    Page::factory()->create(['slug' => 'checkout']);
})->throws(LogicException::class);

it('draws no delete for a System Page, even to a User holding delete pages', function () {
    $this->actingAs(User::factory()->deletingPages()->create());

    $home = Page::factory()->system(SystemPage::Home)->create();
    $ordinary = Page::factory()->create();

    Livewire::test(EditPage::class, ['record' => $home->getKey()])
        ->assertActionHidden('delete');

    Livewire::test(ListPages::class)
        ->assertTableBulkActionVisible('delete')
        ->assertCanSeeTableRecords([$home, $ordinary])
        ->selectTableRecords([$home->getKey(), $ordinary->getKey()])
        ->callTableBulkAction('delete', [$home, $ordinary]);

    expect(Page::query()->pluck('id')->all())->toBe([$home->id]);
});

it('never deletes a System Page, nor changes any Page\'s role', function (Closure $act) {
    $page = Page::factory()->system(SystemPage::BulkOrder)->create();

    $act($page);
})->with([
    'delete' => [fn (Page $page) => $page->delete()],
    'another role' => [fn (Page $page) => $page->update(['system_role' => SystemPage::MyOrders])],
    'no role' => [fn (Page $page) => $page->update(['system_role' => null])],
    'a role for an ordinary Page' => [fn () => Page::factory()->create()->update(['system_role' => SystemPage::MyOrders])],
])->throws(LogicException::class);

it('lets a System Page be reworded and reslugged while keeping its role', function () {
    $this->actingAs(User::factory()->authoringPages()->create());

    $page = Page::factory()->system(SystemPage::BulkOrder)->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertSee('Bulk Order')
        ->fillForm(['title' => 'পাইকারি অর্ডার', 'slug' => 'paikari'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($page->refresh())
        ->title->toBe('পাইকারি অর্ডার')
        ->slug->toBe('paikari')
        ->system_role->toBe(SystemPage::BulkOrder);
});
