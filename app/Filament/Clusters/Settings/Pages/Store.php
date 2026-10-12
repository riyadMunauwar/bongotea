<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\ImageSlot;
use App\Support\PhoneNumber;
use App\Support\Settings\StoreSettings;
use BackedEnum;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Who the store is, set once and drawn on every storefront page: the name
 * and logo in the header, the numbers and address in the footer and drawer,
 * and what a shared link says when a page says nothing of its own.
 */
class Store extends SettingsPage
{
    protected static string $group = StoreSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $title = 'Store';

    /** Right after General: the store's own identity before anything it sells or sends. */
    protected static ?int $navigationSort = 1;

    /**
     * @return array<Section>
     */
    protected function formComponents(): array
    {
        return [
            Section::make('Identity')
                ->description('Drawn in the header, the footer and every page title.')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Store name')
                        ->required()
                        ->maxLength(60),

                    TextInput::make('tagline')
                        ->label('Tagline')
                        ->maxLength(80)
                        ->helperText('Under the name in the header, and after it in the page title.'),

                    ImageSlot::StoreLogo->fit(self::image('logo', 'Logo'))
                        ->helperText('Replaces the drawn leaf mark and the name in the header. Leave empty to keep them. '.ImageSlot::StoreLogo->advice()),
                ]),

            Section::make('Reaching the store')
                ->description('Shown in the top bar, the footer and the mobile menu.')
                ->columns(2)
                ->schema([
                    self::phone('hotline', 'Hotline'),
                    self::phone('whatsapp', 'WhatsApp number'),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(120),

                    TextInput::make('hours')
                        ->label('Opening hours')
                        ->maxLength(60)
                        ->helperText('As buyers should read it, e.g. সকাল ৯টা – রাত ৯টা.'),

                    TextInput::make('address')
                        ->label('Address')
                        ->maxLength(160)
                        ->columnSpanFull(),
                ]),

            Section::make('Search and sharing')
                ->description('What a search result or a link shared on Facebook or WhatsApp shows when a page has nothing of its own.')
                ->collapsible()
                ->collapsed()
                ->columns(2)
                ->schema([
                    Textarea::make('description')
                        ->label('Description')
                        ->rows(3)
                        ->maxLength(300)
                        ->columnSpanFull(),

                    ImageSlot::ShareImage->fit(self::image('shareImage', 'Default share image')),
                ]),
        ];
    }

    /**
     * A number the storefront dials or messages, so one the dialling rules
     * in General Settings can read; blank is allowed and hides it.
     */
    private static function phone(string $name, string $label): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->tel()
            ->maxLength(20)
            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                if (filled($value) && ! PhoneNumber::isValid((string) $value)) {
                    $fail('Enter a phone number this store can dial, e.g. 01700000000.');
                }
            })
            ->helperText('Leave blank to hide it.');
    }

    /**
     * An image on the public `local` disk (ADR-0011); the row keeps only the
     * path, and blank draws nothing.
     */
    private static function image(string $name, string $label): FileUpload
    {
        return FileUpload::make($name)
            ->label($label)
            ->disk(StoreSettings::IMAGE_DISK)
            ->directory(StoreSettings::IMAGE_DIRECTORY)
            ->visibility('public')
            ->image()
            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'])
            ->maxSize(1024)
            ->imagePreviewHeight('80');
    }
}
