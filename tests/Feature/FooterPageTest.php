<?php

use App\Enums\SystemPage;
use App\Filament\Pages\Footer;
use App\Models\Menu;
use App\Models\Page;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\FooterSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->authoringPages()->create());
});

function reloadedFooter(): FooterSettings
{
    app()->forgetInstance(FooterSettings::class);

    return app(FooterSettings::class);
}

it('opens on the mockup blurb with no columns, bottom line or banner, and saves columns in the order given', function () {
    $order = Menu::factory()->create(['name' => 'Order links']);
    $about = Menu::factory()->create(['name' => 'About links']);
    $bottom = Menu::factory()->create(['name' => 'Bottom links']);

    Livewire::test(Footer::class)
        ->assertSchemaStateSet([
            'blurb' => 'চায়ের দোকান, রেস্টুরেন্ট ও ব্যবসার জন্য বাছাই করা চা। বেশি নিলে প্রতি কেজির দাম কম।',
            'columns' => [],
            'bottomMenuId' => null,
        ])
        ->set('data.columns', [
            ['heading' => 'অর্ডার', 'menuId' => $order->id],
            ['heading' => 'Bongotea', 'menuId' => $about->id],
        ])
        ->fillForm(['blurb' => 'নতুন কথা', 'bottomMenuId' => $bottom->id])
        ->call('save')
        ->assertHasNoFormErrors();

    $footer = reloadedFooter();

    expect($footer->blurb)->toBe('নতুন কথা')
        ->and($footer->columns)->toBe([
            ['heading' => 'অর্ডার', 'menuId' => $order->id],
            ['heading' => 'Bongotea', 'menuId' => $about->id],
        ])
        ->and($footer->bottomMenuId)->toBe($bottom->id)
        ->and($footer->paymentBanner)->toBeNull();
});

it('reorders and removes columns, and unassigns the bottom line', function () {
    [$first, $second, $third] = Menu::factory()->count(3)->create()->all();
    app(FooterSettings::class)->with([
        'columns' => [
            ['heading' => 'এক', 'menuId' => $first->id],
            ['heading' => 'দুই', 'menuId' => $second->id],
            ['heading' => 'তিন', 'menuId' => $third->id],
        ],
        'bottomMenuId' => $first->id,
    ])->save();

    Livewire::test(Footer::class)
        ->set('data.columns', [
            ['heading' => 'তিন', 'menuId' => $third->id],
            ['heading' => 'এক', 'menuId' => $first->id],
        ])
        ->fillForm(['bottomMenuId' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(reloadedFooter()->columns)->toBe([
        ['heading' => 'তিন', 'menuId' => $third->id],
        ['heading' => 'এক', 'menuId' => $first->id],
    ])
        ->and(reloadedFooter()->bottomMenuId)->toBeNull();
});

it('asks each column for a Menu', function () {
    Livewire::test(Footer::class)
        ->set('data.columns', ['empty' => ['heading' => 'খালি', 'menuId' => null]])
        ->call('save')
        ->assertHasFormErrors(['columns.empty.menuId' => 'required']);

    expect(Setting::query()->find(FooterSettings::key()))->toBeNull();
});

it('stores the Payment Banner on the public local disk, and removes it', function () {
    Storage::fake(FooterSettings::IMAGE_DISK);
    Storage::fake('staging');

    Livewire::test(Footer::class)
        ->fillForm(['paymentBanner' => UploadedFile::fake()->image('banner.png', 1200, 120)])
        ->call('save')
        ->assertHasNoFormErrors();

    $path = reloadedFooter()->paymentBanner;

    expect($path)->toStartWith(FooterSettings::IMAGE_DIRECTORY.'/')
        ->and(reloadedFooter()->paymentBannerUrl())->toEndWith("/storage/{$path}");
    Storage::disk(FooterSettings::IMAGE_DISK)->assertExists($path);

    Livewire::test(Footer::class)
        ->set('data.paymentBanner', null)
        ->call('save')
        ->assertHasNoFormErrors();

    expect(reloadedFooter()->paymentBanner)->toBeNull()
        ->and(reloadedFooter()->paymentBannerUrl())->toBeNull();
});

it('reads a damaged footer row as what it can, dropping Menus that are gone, rather than failing every request', function () {
    Page::factory()->system(SystemPage::Home)->create();
    $kept = Menu::factory()->create();
    $gone = Menu::factory()->create();

    Setting::query()->create(['key' => FooterSettings::key(), 'value' => [
        'columns' => ['not a column', ['heading' => ['oops'], 'menuId' => $kept->id], ['heading' => 'গায়েব', 'menuId' => $gone->id + 100]],
        'bottomMenuId' => 'oops',
    ]]);

    expect(FooterSettings::load()->columns)->toBe([
        ['heading' => '', 'menuId' => $kept->id],
        ['heading' => 'গায়েব', 'menuId' => null],
    ])
        ->and(FooterSettings::load()->bottomMenuId)->toBeNull();

    Setting::query()->whereKey(FooterSettings::key())->update(['value' => json_encode(['columns' => 'oops', 'bottomMenuId' => $gone->id])]);
    Menu::query()->whereKey($gone->id)->delete();

    expect(FooterSettings::load()->columns)->toBe([])
        ->and(FooterSettings::load()->bottomMenuId)->toBeNull();

    $this->get('/')->assertOk();
});
