<?php

namespace App\Filament\Resources\Pages\Schemas\Tracking;

use App\Models\Destination;
use App\Support\Tracking\TrackingPlans;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Where this Page reports.
 *
 * Blank is the answer for nearly every Page, and it is the one that keeps
 * working: it means the Destination marked default for each Provider, so a
 * Merchant who swaps their Facebook account in Settings has swapped it
 * everywhere. A Page picks its own where it belongs to a different brand or a
 * different client.
 *
 * The picker lists every Destination, switched off ones included and said to
 * be so, because a Merchant pausing a campaign should be able to see where the
 * Page still points while it is paused.
 */
class DestinationChoice
{
    public static function section(): Section
    {
        return Section::make('Destination')
            ->description('The ad account this Page reports to. Leave it blank and it reports to whichever Destination is marked default for each Provider, which is what nearly every Page wants.')
            ->columns(1)
            ->schema([
                self::control(),
            ]);
    }

    public static function control(): Select
    {
        return Select::make('tracking_config.destination_id')
            ->label('Destination')
            ->placeholder('Use the default for each Provider')
            ->options(fn (): array => self::listable())
            /** So the preview below follows the choice as it is made, rather than at the next save. */
            ->live()
            ->helperText(fn (Get $get): string => self::help($get));
    }

    /**
     * Every Destination the Merchant has, by id, each said in the Provider's
     * own terms — and a switched-off one marked as such rather than hidden: a
     * Page pointed at one is taking a campaign break, and the picker is where
     * that is read back.
     *
     * @return array<int, string>
     */
    private static function listable(): array
    {
        $options = [];

        foreach (Destination::query()->orderBy('id')->get() as $destination) {
            $options[$destination->getKey()] = sprintf(
                '%s — %s%s',
                $destination->label,
                $destination->provider->getLabel(),
                $destination->enabled ? '' : ' (switched off)',
            );
        }

        return $options;
    }

    /**
     * What this Page reports to as it stands, worded from the same reading a
     * Moment takes — so a Merchant is told where a deleted Destination leaves
     * them rather than finding out from an empty Events Manager.
     */
    private static function help(Get $get): string
    {
        $plan = app(TrackingPlans::class)->forConfig(Events::asConfig($get));

        if ($plan->reportsNowhere()) {
            return 'Nothing is reported: there is no Destination switched on for this Page. A Page that reports nowhere carries no tracking script at all and serves exactly the document you wrote.';
        }

        $labels = array_map(fn (Destination $destination): string => $destination->label, $plan->destinations());

        return 'This Page reports to '.implode(', ', $labels).'. A Destination that has been deleted falls back to the default rather than leaving the Page reporting nowhere.';
    }
}
