<?php

namespace App\Filament\Resources\Shipments\Schemas;

use App\Models\Shipment;
use App\Models\ShipmentEvent;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Icons\Heroicon;

/**
 * Everything that happened to one attempt, as the system came to know it.
 *
 * Written once and drawn twice — on the Shipment's own screen, and once per
 * attempt on the Order's — because the two are the same reading of the same
 * rows, and a second copy would be a way for a parcel's history to be told
 * two different ways on two screens two clicks apart.
 *
 * Three things per entry, and the third is the reason this is not a list of
 * dates: where the parcel stood in our words, the Courier's own word for it
 * kept verbatim, and when — whose time that is being said out loud, because
 * the Courier's time and ours are different facts (ADR-0018) and most couriers
 * give only the second.
 */
class ShipmentTimeline
{
    /**
     * Said where the Courier dated the happening itself.
     *
     * Worded as the Courier's claim rather than as a fact, because it is one:
     * this is what a company in Dhaka says about a parcel, and it is what a
     * User quotes back at them when it turns out to be wrong.
     */
    public const COURIERS_TIME = 'The courier says';

    /**
     * Said where the Courier gave no time at all — which is most of what most
     * couriers report.
     *
     * The honest reading, and never dressed up as a delivery time: a parcel a
     * sweep found Delivered at nine was delivered at some point before nine,
     * and the half-hour the sweep runs on is the whole of the precision here.
     */
    public const OUR_TIME = 'We found out';

    /**
     * What stands where a state reached us in no words of the Courier's own.
     *
     * Blank would read as a word nobody bothered to copy down. This is a
     * booking not yet dispatched, or a Courier that reported a state and
     * named it nothing — and the difference matters to whoever is about to
     * ring that Courier quoting it.
     */
    public const NOTHING_SAID = 'The courier said nothing';

    /**
     * The Courier's own word for a state, verbatim and copyable, because its
     * whole purpose is to be read down a phone line to that Courier's support
     * team. Our seven states are what the system reasons with; this is what
     * the company holding the parcel calls it.
     *
     * Written once because it is drawn twice — on every line of the timeline,
     * and again on the Shipment's own record, where it saves a User scrolling
     * for the first thing they will be asked for.
     */
    public static function couriersOwnWord(): TextEntry
    {
        return TextEntry::make('raw_status')
            ->label('The courier\'s own word')
            ->copyable()
            ->placeholder(self::NOTHING_SAID);
    }

    /**
     * One attempt's history, oldest first, as a ruled table.
     *
     * Handed the Events themselves as records rather than as form data, for
     * the reason `OrderDocument::payments()` gives: the Order's edit screen
     * draws this too, and it hydrates whatever a repeatable holds. What a
     * Courier said is not a thing anybody types.
     */
    public static function entries(): RepeatableEntry
    {
        return RepeatableEntry::make('events')
            ->hiddenLabel()
            ->state(fn (Shipment $record): array => $record->events->all())
            ->placeholder('Nothing has happened to this parcel yet.')
            ->table([
                TableColumn::make('State'),
                TableColumn::make('The courier\'s own word'),
                TableColumn::make('When'),
            ])
            /**
             * Labels repeat the column headings on purpose. Filament hides
             * them once the table is wide enough to show its header row, and
             * falls back to them when it stacks on a narrow screen.
             */
            ->schema([
                TextEntry::make('status')
                    ->label('State')
                    ->badge(),

                self::couriersOwnWord(),

                /**
                 * One instant, and the words saying whose it is — in the cell
                 * itself and never in the label. A `table()` repeatable hides
                 * every per-row label from `@xl` up
                 * (`filament/infolists/.../repeatable.css`), keeping them only
                 * for the stacked narrow layout, so a distinction carried by a
                 * dynamic label would be invisible on the screen this is
                 * actually read on. It has to be in the value.
                 *
                 * The instant itself is `happenedAt()` — the model's own
                 * reading of "the Courier's time where it gave one, ours
                 * otherwise" — so the timeline and everything else that dates
                 * an Event agree by construction rather than by two people
                 * remembering the same rule.
                 */
                TextEntry::make('happened_at')
                    ->label('When')
                    ->state(fn (ShipmentEvent $record): string => ($record->occurred_at === null ? self::OUR_TIME : self::COURIERS_TIME).' '.$record->happenedAt()->format('M j, Y H:i'))
                    ->color(fn (ShipmentEvent $record): ?string => $record->occurred_at === null ? 'gray' : null)
                    ->hintIcon(
                        fn (ShipmentEvent $record): Heroicon => $record->occurred_at === null
                            ? Heroicon::OutlinedInboxArrowDown
                            : Heroicon::OutlinedTruck,
                        tooltip: fn (ShipmentEvent $record): string => $record->occurred_at === null
                            ? 'The courier gave no time of its own, so this is when we found out — never read it as when the parcel moved.'
                            : 'The courier\'s own time for it. We found out at '.$record->recorded_at->format('M j, Y H:i').'.',
                    ),
            ]);
    }
}
