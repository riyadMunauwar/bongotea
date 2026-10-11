<?php

use App\Enums\MenuLinkType;
use App\Filament\Pages\Footer;
use App\Filament\Pages\Header;
use App\Filament\Resources\Menus\MenuResource;
use App\Filament\Resources\Menus\Pages\CreateMenu;
use App\Filament\Resources\Menus\Pages\EditMenu;
use App\Filament\Resources\Menus\Pages\ListMenus;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use App\Models\User;
use App\Support\Settings\FooterSettings;
use App\Support\Settings\HeaderSettings;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Illuminate\Contracts\Support\Arrayable;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    $this->actingAs(User::factory()->authoringPages()->create());
});

/**
 * A node as the builder submits it.
 *
 * @param  array<string, mixed>  $target
 * @param  list<array<string, mixed>>  $children
 * @return array<string, mixed>
 */
function node(string $label, MenuLinkType $type, array $target = [], array $children = []): array
{
    return ['key' => $label, 'label' => $label, 'type' => $type->value, 'note' => null, 'target_id' => null, 'anchor' => null, 'url' => null, ...$target, 'children' => $children];
}

/**
 * The Menu's items as label, parent label and position, in the order written.
 *
 * @return list<array{0: string, 1: ?string, 2: int}>
 */
function storedItems(Menu $menu): array
{
    return $menu->items()->reorder()->orderBy('id')->with('parent')->get()
        ->map(fn (MenuItem $item): array => [$item->label, $item->parent?->label, $item->position])
        ->all();
}

it('draws Header, Footer and Menus under Pages, and refuses them to a User who cannot author Pages', function () {
    $this->get(Header::getUrl())->assertOk();
    $this->get(Footer::getUrl())->assertOk();
    $this->get(MenuResource::getUrl('index'))->assertOk();

    $pages = collect(Filament::getCurrentOrDefaultPanel()->getNavigation())
        ->flatMap(fn (NavigationGroup $group): array|Arrayable => $group->getItems())
        ->first(fn (NavigationItem $item): bool => $item->getLabel() === 'Pages');

    expect(collect($pages->getChildItems())->map(fn (NavigationItem $item): string => $item->getLabel())->all())
        ->toBe(['Header', 'Footer', 'Menus']);

    $this->actingAs(User::factory()->managingSettings()->create());

    $this->get(Header::getUrl())->assertForbidden();
    $this->get(Footer::getUrl())->assertForbidden();
    $this->get(MenuResource::getUrl('index'))->assertForbidden();
    $this->get(MenuResource::getUrl('edit', ['record' => Menu::factory()->create()]))->assertForbidden();
});

it('creates a Menu from its name alone and lands on the builder', function () {
    Livewire::test(CreateMenu::class)
        ->fillForm(['name' => 'Main menu'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(MenuResource::getUrl('edit', ['record' => Menu::query()->sole()]));
});

it('lists each Menu with its link count and where it is assigned', function () {
    $header = Menu::factory()->has(MenuItem::factory()->count(2), 'items')->create(['name' => 'Main menu']);
    $draft = Menu::factory()->create(['name' => 'Draft']);
    app(HeaderSettings::class)->with(['menuId' => $header->id])->save();

    Livewire::test(ListMenus::class)
        ->assertCanSeeTableRecords([$header, $draft])
        ->assertTableColumnStateSet('items_count', 2, $header)
        ->assertTableColumnStateSet('assigned_to', ['Header'], $header)
        ->assertTableColumnStateSet('assigned_to', null, $draft);
});

it('saves a submitted nested tree in order, three levels deep, with one activity entry', function () {
    $about = Page::factory()->published()->create();
    $menu = Menu::factory()->create();

    Livewire::test(EditMenu::class, ['record' => $menu->getKey()])
        ->set('tree', [
            node('আমাদের চা', MenuLinkType::Heading, children: [
                node('প্রিমিয়াম', MenuLinkType::Anchor, ['anchor' => '#category-premium', 'note' => 'সেরা'], [
                    node('ফোন', MenuLinkType::Url, ['url' => 'tel:+8801700000000']),
                ]),
                node('সম্পর্কে', MenuLinkType::Page, ['target_id' => $about->id]),
            ]),
            node('যোগাযোগ', MenuLinkType::Url, ['url' => '/contact']),
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('hasUnsavedChanges', false);

    expect(storedItems($menu))->toBe([
        ['আমাদের চা', null, 0],
        ['প্রিমিয়াম', 'আমাদের চা', 0],
        ['ফোন', 'প্রিমিয়াম', 0],
        ['সম্পর্কে', 'আমাদের চা', 1],
        ['যোগাযোগ', null, 1],
    ])
        ->and(MenuItem::query()->where('label', 'প্রিমিয়াম')->sole()->only(['anchor', 'note', 'linkable_id']))
        ->toBe(['anchor' => 'category-premium', 'note' => 'সেরা', 'linkable_id' => null])
        ->and(MenuItem::query()->where('label', 'সম্পর্কে')->sole()->linkable->is($about))->toBeTrue()
        ->and(Activity::query()->where('event', 'menu_saved')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'menu_saved')->sole()->subject->is($menu))->toBeTrue();
});

it('replaces the tree whole on every Save', function () {
    $menu = Menu::factory()->create();
    MenuItem::factory()->for($menu)->count(3)->create();

    Livewire::test(EditMenu::class, ['record' => $menu->getKey()])
        ->assertCount('tree', 3)
        ->set('tree', [node('হোম', MenuLinkType::Url, ['url' => '/'])])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(storedItems($menu))->toBe([['হোম', null, 0]])
        ->and(MenuItem::query()->count())->toBe(1);
});

it('refuses a Save that breaks a rule, marking the link and writing nothing', function (Closure $tree, string $refused) {
    $menu = Menu::factory()->create(['name' => 'Main menu']);
    MenuItem::factory()->for($menu)->create(['label' => 'আগের']);

    Livewire::test(EditMenu::class, ['record' => $menu->getKey()])
        ->fillForm(['name' => 'Renamed'])
        ->set('tree', $tree())
        ->call('save')
        ->assertSet('refusedKey', $refused);

    expect(storedItems($menu))->toBe([['আগের', null, 0]])
        ->and($menu->refresh()->name)->toBe('Main menu')
        ->and(Activity::query()->where('event', 'menu_saved')->exists())->toBeFalse()
        ->and(lastNotification()?->getBody())->toContain($refused);
})->with([
    'a fourth level' => [fn (): array => [
        node('এক', MenuLinkType::Heading, children: [node('দুই', MenuLinkType::Heading, children: [node('তিন', MenuLinkType::Heading, children: [
            node('চার', MenuLinkType::Url, ['url' => '/deep']),
        ])])]),
    ], 'চার'],
    'a Page link with no Page' => [fn (): array => [node('পাতা', MenuLinkType::Page, ['url' => '/about'])], 'পাতা'],
    'a missing Page' => [fn (): array => [node('গায়েব', MenuLinkType::Page, ['target_id' => 999999])], 'গায়েব'],
    'a bad URL' => [fn (): array => [node('খারাপ', MenuLinkType::Url, ['url' => 'javascript:alert(1)'])], 'খারাপ'],
]);

it('moves a branch with its children, and refuses a move past three levels', function () {
    $menu = Menu::factory()->create();

    $builder = Livewire::test(EditMenu::class, ['record' => $menu->getKey()])
        ->set('tree', [
            node('এক', MenuLinkType::Heading, children: [node('দুই', MenuLinkType::Heading, children: [node('তিন', MenuLinkType::Url, ['url' => '/'])])]),
            node('বাইরে', MenuLinkType::Heading, children: [node('ভেতরে', MenuLinkType::Url, ['url' => '/x'])]),
        ])
        ->call('moveItem', 'দুই', null, 0);

    expect(collect($builder->get('tree'))->pluck('label')->all())->toBe(['দুই', 'এক', 'বাইরে'])
        ->and($builder->get('tree.0.children.0.label'))->toBe('তিন')
        ->and($builder->get('hasUnsavedChanges'))->toBeTrue();

    $builder->call('moveItem', 'বাইরে', 'তিন', 0);

    expect(collect($builder->get('tree'))->pluck('label')->all())->toBe(['দুই', 'এক', 'বাইরে']);
});

it('edits a link in the slide-over and removes one with its children', function () {
    $menu = Menu::factory()->create();

    Livewire::test(EditMenu::class, ['record' => $menu->getKey()])
        ->set('tree', [
            node('আরও', MenuLinkType::Heading, children: [node('ফোন', MenuLinkType::Url, ['url' => 'tel:1'])]),
            node('হোম', MenuLinkType::Url, ['url' => '/']),
        ])
        ->callAction('editItem', data: ['label' => 'শুরু', 'type' => MenuLinkType::Anchor->value, 'anchor' => 'how'], arguments: ['key' => 'হোম'])
        ->assertSet('tree.1.label', 'শুরু')
        ->assertSet('tree.1.anchor', 'how')
        ->assertSet('tree.1.url', null)
        ->callAction('removeItem', arguments: ['key' => 'আরও'])
        ->assertCount('tree', 1)
        ->call('save')
        ->assertHasNoFormErrors();

    expect(storedItems($menu))->toBe([['শুরু', null, 0]]);
});

it('refuses to delete the header Menu, naming the place, and deletes an unassigned one', function () {
    $header = Menu::factory()->create(['name' => 'Main menu']);
    $draft = Menu::factory()->create();
    app(HeaderSettings::class)->with(['menuId' => $header->id])->save();

    Livewire::test(EditMenu::class, ['record' => $header->getKey()])
        ->callAction('delete');

    expect($header->fresh())->not->toBeNull()
        ->and(lastNotification()?->getBody())->toContain('assigned to Header');

    Livewire::test(ListMenus::class)
        ->callTableAction('delete', $draft);

    expect($draft->fresh())->toBeNull();
});

it('refuses to delete a Menu a footer column or the bottom line draws, naming the place', function () {
    $column = Menu::factory()->create();
    $bottom = Menu::factory()->create();
    app(FooterSettings::class)->with([
        'columns' => [['heading' => 'অর্ডার', 'menuId' => $column->id]],
        'bottomMenuId' => $bottom->id,
    ])->save();

    Livewire::test(ListMenus::class)
        ->assertTableColumnStateSet('assigned_to', ['Footer column “অর্ডার”'], $column)
        ->callTableAction('delete', $column);

    expect($column->fresh())->not->toBeNull()
        ->and(lastNotification()?->getBody())->toContain('assigned to Footer column “অর্ডার”');

    Livewire::test(EditMenu::class, ['record' => $bottom->getKey()])
        ->callAction('delete');

    expect($bottom->fresh())->not->toBeNull()
        ->and(lastNotification()?->getBody())->toContain('assigned to Footer bottom line');
});
