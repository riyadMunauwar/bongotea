<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Orders\CapturedAnswer;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * The parts the view and edit screens hold in common.
 *
 * Both screens are the same document — the same blocks in the same places, so
 * that opening the editor moves nothing on screen except which cells accept
 * typing. Anything identical between them belongs here rather than being
 * written twice and drifting apart.
 */
class OrderDocument
{
    /**
     * What stands where a detail the Checkout never asked for would have been.
     *
     * Said once for both screens, and said in those words rather than left
     * blank: an empty cell reads as an Order someone filled in badly, and
     * whether to chase a customer for the missing line turns on which of the
     * two it is.
     */
    public const NOT_ASKED = 'Not asked for';

    /**
     * What stands where a question was asked and the Customer answered nothing.
     *
     * The other half of the distinction NOT_ASKED draws, and the half worth a
     * phone call: this Order was taken on a form that put the question in front
     * of somebody who left it alone.
     */
    public const NOT_ANSWERED = 'Left blank';

    /**
     * Statuses that mean "do not send this parcel".
     *
     * The document is deliberately shaped to look routine, which is exactly why
     * these need more than the status badge: the cost of skimming past one is a
     * courier delivering goods nobody is going to pay for.
     *
     * @return array<int, Callout>
     */
    public static function warnings(): array
    {
        return [
            Callout::make('Cancelled — this order will not be fulfilled.')
                ->description('The order genuinely happened, so it stays on the books. Do not hand it to a courier.')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->danger()
                ->visible(fn (?Order $record): bool => $record?->status === OrderStatus::Cancelled)
                ->columnSpanFull(),

            /**
             * Reachable because OrderResource drops the SoftDeletingScope from
             * its route binding, so a trashed Order still opens rather than
             * 404ing. A deleted Order is one that should never have existed —
             * a different thing from a Cancelled one, and both can be true.
             */
            Callout::make('Deleted — this order has been removed.')
                ->description('Restore it before working it. A deleted order is one that should never have existed, which is not the same as a cancelled one.')
                ->icon(Heroicon::OutlinedTrash)
                ->danger()
                ->visible(fn (?Order $record): bool => $record?->trashed() ?? false)
                ->columnSpanFull(),
        ];
    }

    /**
     * The questions the Page's Merchant added of their own, answered.
     *
     * Read from the Order and never from the Page: the wording, the control and
     * the chosen option's label were all copied in at Capture, so this reads
     * the same after the question is reworded, deleted, or the Page itself
     * force-deleted (ADR-0006).
     *
     * Absent where the Checkout asked none, which is most Orders. An empty
     * "also asked" heading over nothing is a section a fulfiller learns to skip.
     *
     * Read-only on both screens. What was agreed at Capture is what gets
     * collected at the door, and these answers are agreed the way a Product's
     * name and price are.
     *
     * @return array<int, Section>
     */
    public static function answers(): array
    {
        return [
            Section::make('Also asked')
                ->columnSpanFull()
                ->visible(fn (?Order $record): bool => filled($record?->answers))
                ->schema([
                    RepeatableEntry::make('answers')
                        ->hiddenLabel()
                        /**
                         * Handed the two lines a person reads rather than the
                         * answers themselves. The edit screen hydrates whatever
                         * a repeatable holds, and an Order's snapshot is
                         * readonly by construction — as what was agreed at
                         * Capture should be.
                         */
                        ->state(fn (?Order $record): array => array_map(
                            fn (CapturedAnswer $answer): array => [
                                'label' => $answer->label,
                                'display' => $answer->display,
                            ],
                            $record?->answers ?? [],
                        ))
                        ->table([
                            TableColumn::make('Question'),
                            TableColumn::make('Answer'),
                        ])
                        /**
                         * Labels repeat the column headings on purpose. Filament
                         * hides them once the table is wide enough to show its
                         * header row, and falls back to them when it stacks on a
                         * narrow screen.
                         */
                        ->schema([
                            TextEntry::make('label')
                                ->label('Question')
                                ->hintIcon(
                                    Heroicon::OutlinedQuestionMarkCircle,
                                    tooltip: 'As asked. The question may have been reworded or removed from the page since.',
                                ),

                            TextEntry::make('display')
                                ->label('Answer')
                                ->placeholder(self::NOT_ANSWERED),
                        ]),
                ]),
        ];
    }
}
