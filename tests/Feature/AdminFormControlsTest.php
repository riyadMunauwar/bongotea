<?php

use App\Enums\ImageSlot;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;

/**
 * What every form and filter in the panel draws its pickers as, however the
 * screen that builds them was written: the panel's own dropdown, never the
 * browser's.
 */
it('draws every select, date picker and select filter as the panel dropdown rather than the browser one', function () {
    expect(Select::make('status')->isNative())->toBeFalse()
        ->and(DatePicker::make('supplied_on')->isNative())->toBeFalse()
        ->and(DateTimePicker::make('sent_at')->isNative())->toBeFalse()
        ->and(SelectFilter::make('status')->isNative())->toBeFalse();
});

it('still lets one screen ask for the browser select on purpose', function () {
    expect(Select::make('status')->native()->isNative())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Images sized to where the storefront draws them
|--------------------------------------------------------------------------
*/

it('opens the crop editor at the shape the storefront draws an image, with Free left to skip it', function (ImageSlot $slot, int $width, int $height, string $ratio) {
    $upload = $slot->fit(FileUpload::make('image'));

    expect($upload->hasImageEditor())->toBeTrue()
        ->and($upload->getImageEditorViewportWidth())->toBe($width)
        ->and($upload->getImageEditorViewportHeight())->toBe($height)
        ->and(array_keys($upload->getImageEditorAspectRatioOptionsForJs()))->toBe([$ratio, 'Free']);
})->with([
    'a Product photo, square' => [ImageSlot::ProductPhoto, 1200, 1200, '1:1'],
    'a Brand logo, square' => [ImageSlot::BrandLogo, 256, 256, '1:1'],
    'a hero slide, wide' => [ImageSlot::HeroSlide, 2400, 1000, '12:5'],
    'a story card, upright' => [ImageSlot::PortraitCard, 800, 1000, '4:5'],
    'a share image, link-preview shaped' => [ImageSlot::ShareImage, 1200, 630, '1.91:1'],
]);

it('draws a single upload at its slot shape, and leaves a multi-image gallery as a grid whichever is said first', function () {
    expect(ImageSlot::HeroSlide->fit(FileUpload::make('image'))->getPanelAspectRatio())->toBe('12:5')
        ->and(ImageSlot::ProductPhoto->fit(FileUpload::make('gallery'))->multiple()->getPanelAspectRatio())->toBeNull()
        ->and(ImageSlot::ProductPhoto->fit(FileUpload::make('gallery'))->multiple()->getPanelLayout())->toBe('grid');
});

it('offers no crop for a logo or banner whose width follows the image', function (ImageSlot $slot) {
    expect($slot->fit(FileUpload::make('image'))->hasImageEditor())->toBeFalse();
})->with([
    'the store logo' => [ImageSlot::StoreLogo],
    'a payment method logo' => [ImageSlot::PaymentLogo],
    'the footer payment banner' => [ImageSlot::PaymentBanner],
]);
