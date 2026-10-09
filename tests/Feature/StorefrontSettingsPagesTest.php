<?php

use App\Enums\MenuLinkType;
use App\Enums\SystemPage;
use App\Filament\Clusters\Settings\Pages\Announcement;
use App\Filament\Clusters\Settings\Pages\Menus;
use App\Filament\Clusters\Settings\Pages\Store;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\AnnouncementSettings;
use App\Support\Settings\MenuSettings;
use App\Support\Settings\StoreSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->managingSettings()->create());
});

it('lists the storefront pages right after General in the Settings cluster', function () {
    $order = collect(SettingsCluster::getClusteredComponents())
        ->sortBy(fn (string $component): int => $component::getNavigationSort())
        ->map(fn (string $component): string => $component::getNavigationLabel())
        ->values()
        ->all();

    expect($order)->toBe(['General', 'Store', 'Announcement', 'Menus', 'Pricing', 'Payment Methods', 'Tracking Destinations', 'Tracking', 'Couriers', 'SMS', 'Inventory', 'Users', 'Roles']);

    foreach ([Store::class, Announcement::class, Menus::class] as $page) {
        $this->get($page::getUrl())->assertOk()->assertSeeLivewire($page);
    }
});

it('forbids the storefront pages to a User who cannot manage settings', function () {
    $this->actingAs(User::factory()->create());

    foreach ([Store::class, Announcement::class, Menus::class] as $page) {
        $this->get($page::getUrl())->assertForbidden();
    }
});

it('opens the Store page on the mockup identity and saves what the Merchant types', function () {
    Livewire::test(Store::class)
        ->assertSchemaStateSet([
            'name' => 'Bongotea',
            'tagline' => 'ব্যবসার জন্য পাইকারি চা',
            'hotline' => '01700000000',
            'email' => 'hello@bongotea.com',
        ])
        ->fillForm([
            'name' => 'Cha Ghor',
            'tagline' => 'দোকানের চা',
            'description' => 'সেরা চা',
            'hotline' => '+880 1812-345678',
            'whatsapp' => '',
            'email' => 'hi@chaghor.com',
            'address' => 'ঢাকা',
            'hours' => 'সারাদিন',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(StoreSettings::class);
    $store = app(StoreSettings::class);

    expect($store->name)->toBe('Cha Ghor')
        ->and($store->tagline)->toBe('দোকানের চা')
        ->and($store->hotline)->toBe('+880 1812-345678')
        ->and($store->whatsapp)->toBe('')
        ->and($store->email)->toBe('hi@chaghor.com');
});

it('refuses a hotline the store could not dial', function () {
    Livewire::test(Store::class)
        ->fillForm(['hotline' => '12345'])
        ->call('save')
        ->assertHasFormErrors(['hotline']);

    expect(Setting::query()->find(StoreSettings::key()))->toBeNull();
});

it('stores the logo on the public local disk and keeps only its path', function () {
    Storage::fake(StoreSettings::IMAGE_DISK);
    Storage::fake('staging');

    Livewire::test(Store::class)
        ->fillForm(['logo' => UploadedFile::fake()->image('logo.png', 200, 80)])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(StoreSettings::class);
    $path = app(StoreSettings::class)->logo;

    expect($path)->toStartWith(StoreSettings::IMAGE_DIRECTORY.'/');
    Storage::disk(StoreSettings::IMAGE_DISK)->assertExists($path);
});

it('switches the announcement off, and asks for a message while it is on', function () {
    Livewire::test(Announcement::class)
        ->assertSchemaStateSet(['enabled' => true, 'pill' => 'অফার'])
        ->fillForm(['enabled' => true, 'message' => ''])
        ->call('save')
        ->assertHasFormErrors(['message' => 'required']);

    Livewire::test(Announcement::class)
        ->fillForm(['enabled' => false, 'message' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(AnnouncementSettings::class);

    expect(app(AnnouncementSettings::class)->enabled)->toBeFalse();
});

it('saves header and footer Menus with Pages by reference and a dropdown', function () {
    $about = Page::factory()->published()->create(['title' => 'About us']);

    Livewire::test(Menus::class)
        ->set('data.header', [
            ['label' => 'আমাদের সম্পর্কে', 'type' => MenuLinkType::Page->value, 'page_id' => $about->id],
            ['label' => 'আরও', 'type' => MenuLinkType::Dropdown->value, 'children' => [
                ['label' => 'ধাপ', 'type' => MenuLinkType::Anchor->value, 'anchor' => 'how', 'note' => '৩ ধাপ'],
            ]],
        ])
        ->set('data.footerColumns', [
            ['heading' => 'সাহায্য', 'links' => [
                ['label' => 'ফেসবুক', 'type' => MenuLinkType::Url->value, 'url' => 'https://facebook.com/bongotea'],
            ]],
        ])
        ->set('data.footerBottomLinks', [])
        ->fillForm(['footerBlurb' => 'নতুন কথা'])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(MenuSettings::class);
    $menus = app(MenuSettings::class);

    expect($menus->header)->toBe([
        ['label' => 'আমাদের সম্পর্কে', 'type' => 'page', 'page_id' => $about->id, 'anchor' => null, 'url' => null, 'note' => null, 'children' => []],
        ['label' => 'আরও', 'type' => 'dropdown', 'page_id' => null, 'anchor' => null, 'url' => null, 'note' => null, 'children' => [
            ['label' => 'ধাপ', 'type' => 'anchor', 'page_id' => null, 'anchor' => 'how', 'url' => null, 'note' => '৩ ধাপ'],
        ]],
    ])
        ->and($menus->footerBlurb)->toBe('নতুন কথা')
        ->and($menus->footerColumns)->toBe([
            ['heading' => 'সাহায্য', 'links' => [
                ['label' => 'ফেসবুক', 'type' => 'url', 'page_id' => null, 'anchor' => null, 'url' => 'https://facebook.com/bongotea', 'note' => null],
            ]],
        ])
        ->and($menus->footerBottomLinks)->toBe([]);
});

it('refuses a Menu URL that is not a path or a web, phone or mail address', function () {
    Livewire::test(Menus::class)
        ->set('data.header', [
            'bad' => ['label' => 'খারাপ', 'type' => MenuLinkType::Url->value, 'url' => 'javascript:alert(1)'],
        ])
        ->call('save')
        ->assertHasFormErrors(['header.bad.url' => 'regex']);
});

it('reads a damaged Menus row as what it can, rather than failing every request', function () {
    Page::factory()->system(SystemPage::Home)->create();

    Setting::query()->create(['key' => MenuSettings::key(), 'value' => [
        'header' => [
            'not an entry',
            ['label' => '', 'type' => 'url', 'url' => '/'],
            ['label' => 'অজানা', 'type' => 'category'],
            ['label' => 'ঠিক', 'type' => 'url', 'url' => '/ok', 'children' => [['label' => 'ঝুলন্ত', 'type' => 'url', 'url' => '/x']]],
        ],
        'footerColumns' => 'oops',
        'footerBottomLinks' => [['label' => 'নিচে', 'type' => 'dropdown']],
    ]]);

    $menus = MenuSettings::load();

    expect($menus->header)->toBe([
        ['label' => 'ঠিক', 'type' => 'url', 'page_id' => null, 'anchor' => null, 'url' => '/ok', 'note' => null, 'children' => []],
    ])
        ->and($menus->footerColumns)->toBe([])
        ->and($menus->footerBottomLinks)->toBe([]);

    $this->get('/')->assertOk();
});
