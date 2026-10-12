<?php

namespace App\Filament\Pages;

use App\Enums\ImageSlot;
use App\Filament\Clusters\Settings\Pages\SettingsPage;
use App\Filament\Resources\Pages\PageResource;
use App\Models\Menu;
use App\Support\Settings\FooterSettings;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * Everything drawn only in the storefront footer: the blurb, the link
 * columns and the bottom line (each a Menu assigned here), and the Payment
 * Banner. The logo and the contacts beside them are Settings → Store's.
 *
 * Storefront content, so it sits under Pages beside the Header and the
 * Menus it assigns, gated with them on `author pages`. It is still one
 * Settings group edited through the settings-page base, which is why the
 * cluster is cleared here.
 */
class Footer extends SettingsPage
{
    protected static ?string $cluster = null;

    protected static string $group = FooterSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static ?string $navigationParentItem = 'Pages';

    /** Under Pages: Header 0, Footer 1, Menus 2. */
    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return PageResource::canAccess();
    }

    /**
     * @return array<Section>
     */
    protected function formComponents(): array
    {
        return [
            Section::make('Blurb')
                ->description('Beside the store name, above the hotline and WhatsApp. The logo and contacts are set in Settings → Store.')
                ->schema([
                    Textarea::make('blurb')
                        ->hiddenLabel()
                        ->rows(2)
                        ->maxLength(240),
                ]),

            Section::make('Columns')
                ->description('Each column is a heading over the top level of a Menu. A column whose Menu has nothing to show is left out.')
                ->schema([
                    Repeater::make('columns')
                        ->hiddenLabel()
                        ->addActionLabel('Add a column')
                        ->defaultItems(0)
                        ->reorderable()
                        ->collapsible()
                        ->columns(2)
                        ->itemLabel(fn (array $state): ?string => $state['heading'] ?? null)
                        ->schema([
                            TextInput::make('heading')
                                ->label('Heading')
                                ->maxLength(40),

                            self::menuSelect('menuId', 'Menu')
                                ->required(),
                        ]),
                ]),

            Section::make('Bottom line')
                ->description('The links after the © on the last line. With no Menu, the © stands alone.')
                ->schema([
                    self::menuSelect('bottomMenuId', 'Bottom line Menu')
                        ->placeholder('No Menu'),
                ]),

            Section::make('Payment Banner')
                ->description('One image of the ways your store takes payment, drawn across the footer above the © line. It shrinks to fit a phone and is never stretched wider than it is. With none, nothing is drawn.')
                ->schema([
                    ImageSlot::PaymentBanner->fit(FileUpload::make('paymentBanner'))
                        ->hiddenLabel()
                        ->disk(FooterSettings::IMAGE_DISK)
                        ->directory(FooterSettings::IMAGE_DIRECTORY)
                        ->visibility('public')
                        ->image()
                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'])
                        ->maxSize(2048)
                        ->imagePreviewHeight('80'),
                ]),
        ];
    }

    private static function menuSelect(string $name, string $label): Select
    {
        return Select::make($name)
            ->label($label)
            ->options(fn (): array => Menu::query()->orderBy('name')->pluck('name', 'id')->all())
            ->searchable();
    }
}
