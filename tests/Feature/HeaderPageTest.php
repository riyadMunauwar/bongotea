<?php

use App\Filament\Pages\Header;
use App\Models\Menu;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\HeaderSettings;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->actingAs(User::factory()->authoringPages()->create());
});

it('opens on the mockup announcement and no header Menu, and assigns the Menu chosen', function () {
    $menu = Menu::factory()->create(['name' => 'Main menu']);

    Livewire::test(Header::class)
        ->assertSchemaStateSet(['menuId' => null, 'announcementEnabled' => true, 'announcementPill' => 'অফার'])
        ->fillForm(['menuId' => $menu->id])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(HeaderSettings::class);

    expect(app(HeaderSettings::class)->menuId)->toBe($menu->id);
});

it('switches the announcement off, and asks for a message while it is on', function () {
    Livewire::test(Header::class)
        ->fillForm(['announcementEnabled' => true, 'announcementMessage' => ''])
        ->call('save')
        ->assertHasFormErrors(['announcementMessage' => 'required']);

    Livewire::test(Header::class)
        ->fillForm(['announcementEnabled' => false, 'announcementMessage' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    app()->forgetInstance(HeaderSettings::class);

    expect(app(HeaderSettings::class)->announcementEnabled)->toBeFalse();
});

it('reads a damaged Menu id as no Menu, rather than failing every request', function () {
    Setting::query()->create(['key' => HeaderSettings::key(), 'value' => ['menuId' => ['oops']]]);

    expect(HeaderSettings::load()->menuId)->toBeNull();
});
