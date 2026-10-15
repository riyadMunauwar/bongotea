<?php

namespace App\Filament\Pages;

use App\Enums\OrderKind;
use App\Filament\Clusters\Settings\Pages\SettingsPage;
use App\Filament\Resources\Pages\PageResource;
use App\Models\Cart;
use App\Models\Menu;
use App\Support\Settings\HeaderSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Everything drawn only in the storefront header: which Menu is its
 * navigation, and the announcement bar above it.
 *
 * Storefront content, so it sits under Pages beside the Menus it assigns,
 * gated with them on `author pages` rather than on the Settings cluster's
 * `manage settings`. It is still one Settings group edited through the
 * settings-page base, which is why the cluster is cleared here.
 */
class Header extends SettingsPage
{
    protected static ?string $cluster = null;

    protected static string $group = HeaderSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWindow;

    protected static ?string $navigationParentItem = 'Pages';

    /** Under Pages: Header 0, Footer 1, Menus 2. */
    protected static ?int $navigationSort = 0;

    public static function canAccess(): bool
    {
        return PageResource::canAccess();
    }

    /**
     * The order buttons open on the Cart each actually opens, the starting
     * one where none was ever chosen.
     *
     * @return array<string, mixed>
     */
    protected function formValues(): array
    {
        $header = app(HeaderSettings::class);

        return [
            ...parent::formValues(),
            'retailCartId' => $header->cartFor(OrderKind::Retail)?->getKey(),
            'bulkCartId' => $header->cartFor(OrderKind::Bulk)?->getKey(),
        ];
    }

    private static function cartSelect(string $name, string $label, OrderKind $kind): Select
    {
        return Select::make($name)
            ->label($label)
            ->required()
            ->options(fn (): array => Cart::query()->where('kind', $kind)->orderBy('sort_order')->orderBy('id')->pluck('name', 'id')->all());
    }

    /**
     * @return array<Section>
     */
    protected function formComponents(): array
    {
        return [
            Section::make('Navigation')
                ->description('The Menu across the top on a computer and in the drawer on a phone. With none, the header draws without a navigation row.')
                ->schema([
                    Select::make('menuId')
                        ->label('Header Menu')
                        ->options(fn (): array => Menu::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->placeholder('No Menu'),
                ]),

            Section::make('Order buttons')
                ->description('খুচরা অর্ডার করুন is drawn to the left of পাইকারি অর্ডার করুন, each opening the Cart chosen here. A button whose Cart is switched off or on no published Page is not drawn.')
                ->columns(2)
                ->schema([
                    self::cartSelect('retailCartId', 'খুচরা অর্ডার করুন opens', OrderKind::Retail),
                    self::cartSelect('bulkCartId', 'পাইকারি অর্ডার করুন opens', OrderKind::Bulk),
                ]),

            Section::make('Announcement bar')
                ->description('One short line above the header on every storefront page. On a phone only the pill and the message show.')
                ->columns(3)
                ->schema([
                    Toggle::make('announcementEnabled')
                        ->label('Show the bar')
                        ->live()
                        ->columnSpanFull(),

                    TextInput::make('announcementPill')
                        ->label('Pill')
                        ->maxLength(16)
                        ->helperText('The gold tag before the message, e.g. অফার. Optional.'),

                    TextInput::make('announcementMessage')
                        ->label('Message')
                        ->required(fn (Get $get): bool => (bool) $get('announcementEnabled'))
                        ->maxLength(80)
                        ->columnSpan(2)
                        ->helperText('Keep it short enough for a phone screen.'),
                ]),
        ];
    }
}
