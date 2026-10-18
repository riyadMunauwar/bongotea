<?php

use App\Enums\BlockGroup;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Cart;
use App\Models\Page;
use App\Models\User;
use App\Support\Pages\Blocks\CartBlock;
use App\Support\Pages\Blocks\HeroCarousel;
use App\Support\Pages\Blocks\InfoCards;
use App\Support\Pages\Blocks\PromiseStrip;
use App\Support\Pages\PageBlocks;
use Filament\Forms\Components\Builder;
use Filament\Schemas\Schema;
use Livewire\Livewire;

/**
 * A Page opens in the editor as an outline: every Block collapsed, each
 * header naming its kind and what it holds. A Block is added from a picker
 * of tiles, by group.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->authoringPages()->create());
});

it('opens every Block of a Page collapsed', function () {
    $page = Page::factory()->withBlocks([
        ['type' => InfoCards::type(), 'data' => ['heading' => 'Why our tea']],
        ['type' => InfoCards::type(), 'data' => ['heading' => 'How we pack']],
    ])->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertSchemaComponentExists('blocks', 'form', fn (Builder $blocks): bool => count($blocks->getItems()) === 2
            && collect($blocks->getItems())->every(fn (Schema $item): bool => $blocks->isCollapsed($item)));
});

it('names each collapsed Block by its kind and what it holds', function (array $data, string $header) {
    $page = Page::factory()->withBlocks([['type' => InfoCards::type(), 'data' => $data]])->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertSee($header);
})->with([
    'its heading' => [['heading' => 'Why our tea', 'anchor' => 'why'], 'Info cards — Why our tea'],
    'its anchor, without a heading' => [['anchor' => 'why'], 'Info cards — #why'],
]);

it('names a Block drawing a Cart by that Cart, and a Block holding nothing yet by its kind alone', function () {
    $cart = Cart::factory()->bulk()->create(['name' => 'Hotel & Restaurant']);
    $page = Page::factory()->withBlocks([
        ['type' => CartBlock::type(), 'data' => ['cart_id' => $cart->getKey(), 'heading' => 'Order in bulk']],
        ['type' => PromiseStrip::type(), 'data' => []],
    ])->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertSee('Cart — Hotel & Restaurant')
        ->assertSee('Promise strip')
        ->assertDontSee('Promise strip —');
});

it('names a hero carousel by its first slide', function () {
    $page = Page::factory()->withBlocks([
        ['type' => HeroCarousel::type(), 'data' => ['slides' => [['title' => 'Fresh from Sylhet'], ['title' => 'Second slide']]]],
    ])->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertSee('Hero carousel — Fresh from Sylhet');
});

it('files every Block kind under exactly one group of the picker, in the groups\' order', function () {
    $groups = PageBlocks::pickerGroups();

    expect(collect($groups)->flatMap(fn (array $group): array => $group['kinds'])->sort()->values()->all())
        ->toBe(collect(PageBlocks::KINDS)->sort()->values()->all())
        ->and(array_column($groups, 'label'))
        ->toBe(array_map(fn (BlockGroup $group): string => $group->getLabel(), BlockGroup::cases()));
});

it('leaves out of the picker a kind the editor no longer offers, and a group left empty', function () {
    expect(PageBlocks::pickerGroups([InfoCards::type()]))
        ->toBe([['label' => BlockGroup::Information->getLabel(), 'kinds' => [InfoCards::class]]]);
});

it('offers the Block kinds as tiles under their groups, each described', function () {
    $page = Page::factory()->withBlocks([['type' => InfoCards::type(), 'data' => []]])->create();

    Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->assertSeeInOrder(['Add a Block', 'Banners', HeroCarousel::label(), 'Catalogue', 'Selling', CartBlock::label(), 'Trust', 'Information', 'Contact &amp; account'], escape: false)
        ->assertSee(HeroCarousel::description())
        ->assertSee('open-block-picker', escape: false);
});
