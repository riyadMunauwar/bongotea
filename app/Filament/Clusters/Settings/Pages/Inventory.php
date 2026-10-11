<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Support\Inventory\DaysOfCover;
use App\Support\Settings\InventorySettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * How much warning the Merchant wants before a shelf runs out.
 *
 * One field, and that is the whole page. Days of Cover keeps up on its own as
 * a campaign moves the rate a Product goes out at, so there is no threshold
 * per Product to set here and none to go stale — one number, meaning the same
 * thing across the whole catalogue, which is the point of measuring cover in
 * days rather than in units.
 *
 * The span the rate is read over is deliberately not offered. It is a
 * constant, because a Merchant moving it would be changing what velocity
 * means rather than saying how much warning they want.
 */
class Inventory extends SettingsPage
{
    protected static string $group = InventorySettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    /** After Couriers at 6 and SMS at 7: the newest category of thing decided once. */
    protected static ?int $navigationSort = 8;

    /**
     * @return array<Section>
     */
    protected function formComponents(): array
    {
        return [
            Section::make('Running out of stock')
                ->description('A product is put on Needs Attention when what is on the shelf will not last this long at the rate it is going out.')
                ->columns(2)
                ->schema([
                    /**
                     * A floor of one day. Nothing is not a threshold, it is
                     * the warning switched off for the whole catalogue and
                     * switched off silently — which is why the group guards
                     * the row as well, for a value that never came through
                     * here.
                     */
                    TextInput::make('thinCoverDays')
                        ->label('Warn me under this many days of cover')
                        ->required()
                        ->integer()
                        ->minValue(1)
                        ->suffix('days')
                        ->helperText('Seven is about how long a supplier takes to bring more. The rate is read over the last '.DaysOfCover::SPAN_DAYS.' days, whatever window a report is showing.'),
                ]),
        ];
    }
}
