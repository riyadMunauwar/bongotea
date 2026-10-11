<?php

use App\Filament\Clusters\Settings\Pages\Store;
use App\Filament\Clusters\Settings\SettingsCluster;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\StoreSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->managingSettings()->create());
});

it('lists Store right after General in the Settings cluster, numbered with no gaps', function () {
    $components = collect(SettingsCluster::getClusteredComponents())
        ->sortBy(fn (string $component): int => $component::getNavigationSort())
        ->values();

    expect($components->map(fn (string $component): string => $component::getNavigationLabel())->all())
        ->toBe(['General', 'Store', 'Delivery Charges', 'Payment Methods', 'Tracking', 'Couriers', 'SMS', 'Inventory', 'Users', 'Roles'])
        ->and($components->map(fn (string $component): int => $component::getNavigationSort())->all())
        ->toBe(range(0, 9));

    $this->get(Store::getUrl())->assertOk()->assertSeeLivewire(Store::class);
});

it('forbids the Store page to a User who cannot manage settings', function () {
    $this->actingAs(User::factory()->create());

    $this->get(Store::getUrl())->assertForbidden();
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
