<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Support\Settings\AnnouncementSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * The bar above the storefront header, where the current offer goes.
 */
class Announcement extends SettingsPage
{
    protected static string $group = AnnouncementSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 2;

    /**
     * @return array<Section>
     */
    protected function formComponents(): array
    {
        return [
            Section::make('Announcement bar')
                ->description('One short line above the header on every storefront page. On a phone only the pill and the message show.')
                ->columns(3)
                ->schema([
                    Toggle::make('enabled')
                        ->label('Show the bar')
                        ->live()
                        ->columnSpanFull(),

                    TextInput::make('pill')
                        ->label('Pill')
                        ->maxLength(16)
                        ->helperText('The gold tag before the message, e.g. অফার. Optional.'),

                    TextInput::make('message')
                        ->label('Message')
                        ->required(fn (Get $get): bool => (bool) $get('enabled'))
                        ->maxLength(80)
                        ->columnSpan(2)
                        ->helperText('Keep it short enough for a phone screen.'),
                ]),
        ];
    }
}
