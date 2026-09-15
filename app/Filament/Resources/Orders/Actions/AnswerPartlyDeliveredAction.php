<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Shipment;
use App\Support\Inventory\ParcelNotAwaitingAnswer;
use App\Support\Inventory\StockLedger;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * "Say what came back": the User answers the one question about a parcel that
 * no Courier can answer.
 *
 * A Courier reporting a partial delivery says what it collected and never
 * which items it is holding, so a Partly Delivered parcel moves no stock at
 * all and its units are neither returned nor lost until somebody establishes
 * which is which (ADR-0021). That is what puts the Order on Needs Attention,
 * and this is the only thing that takes it off again.
 *
 * Lines rather than quantities, because nobody in this system types a
 * quantity. A tick says "this line came back", and how many units that is, at
 * what each of them was worth, is the ledger's own arithmetic read off the
 * movements the parcel already wrote.
 *
 * Ticking nothing is a real answer and is deliberately allowed. A User who
 * rings the Customer and finds that everything stayed with them has
 * established exactly that, and the honest record of it is a settled parcel
 * with no movement asserted — which is also why the answer is a time and a
 * name on the parcel rather than the movements themselves.
 *
 * Offered from the Order and not from the Shipment's own screen, like every
 * decision about an Order: the Shipment resource is read-only, and the lines
 * a User is ticking are the Order's.
 */
class AnswerPartlyDeliveredAction
{
    public static function make(): Action
    {
        return Action::make('answerPartlyDelivered')
            ->label('Say what came back')
            ->icon(Heroicon::OutlinedInboxArrowDown)
            ->color('warning')

            /**
             * Offered while there is a question. A parcel that arrived whole,
             * came back whole or is still out is asking nothing, and one
             * somebody has already answered is answered.
             *
             * Never on a deleted Order, for the reason abandoning is not: the
             * Orders screens drop the soft-delete scope so a deleted one still
             * opens, and an Order that should never have existed is not one to
             * make decisions about.
             */
            ->visible(fn (Order $record): bool => ! $record->trashed() && self::halfArrived($record) !== null)
            ->modalHeading('Which items came back?')
            ->modalDescription(fn (Order $record): string => self::whatTheCourierSaid($record))
            ->modalSubmitActionLabel('Record what came back')
            ->schema([
                CheckboxList::make('order_line_ids')
                    ->label('Came back')
                    ->options(fn (Order $record): array => self::lines($record))
                    ->helperText('Tick the lines the courier is bringing back or has brought back. Leave everything unticked if the customer kept it all — either way the order comes off Needs Attention.'),
            ])
            ->action(function (Order $record, array $data, StockLedger $ledger): void {
                /**
                 * Read fresh, as every action on these screens reads: a
                 * colleague may have answered this between the page being
                 * drawn and the button being pressed.
                 */
                $parcel = self::halfArrived($record->refresh());

                if ($parcel === null) {
                    self::sayThereIsNothingToAnswer();

                    return;
                }

                try {
                    /**
                     * The ledger re-reads the parcel under its own lock, so a
                     * page a minute old is its problem rather than ours: the
                     * colleague who answered in between is refused there, and
                     * told about here.
                     */
                    $ledger->recordPartialReturn($parcel, array_values((array) ($data['order_line_ids'] ?? [])), auth()->user());
                } catch (ParcelNotAwaitingAnswer) {
                    self::sayThereIsNothingToAnswer();

                    return;
                }

                /**
                 * Nothing of the Order's own is refreshed afterwards, unlike
                 * abandoning: what this writes is entirely the parcel's, and
                 * the question is looked for with a fresh query every time
                 * the screen asks whether to offer it.
                 */
                Notification::make()
                    ->title('Recorded')
                    ->body('What came back is on the shelf again at what it cost when it left, and this order is off Needs Attention.')
                    ->success()
                    ->send();
            });
    }

    /**
     * The Order's lines as something to tick: the Product as it was sold and
     * how many of it went out.
     *
     * Read off the Order rather than off the parcel's movements, because this
     * is what a User recognises — the line they are looking at on the screen
     * behind the dialog. What those ticks are worth in units and money is the
     * ledger's own reading of the parcel, and it never exceeds what left.
     *
     * @return array<int|string, string>
     */
    private static function lines(Order $record): array
    {
        return $record->lines()
            ->get()
            ->mapWithKeys(fn (OrderLine $line): array => [
                $line->getKey() => "{$line->quantity} × {$line->product_name}",
            ])
            ->all();
    }

    /**
     * What the Courier actually said, so the User answers against it rather
     * than against a memory of the call.
     */
    private static function whatTheCourierSaid(Order $record): string
    {
        $parcel = self::halfArrived($record);

        if ($parcel === null) {
            return 'No parcel of this order is waiting on an answer.';
        }

        $lastWord = $parcel->raw_status === null
            ? "{$parcel->courier->getLabel()} says part of it arrived"
            : "{$parcel->courier->getLabel()} said “{$parcel->raw_status}”";

        return "Parcel {$parcel->invoice_reference}: {$lastWord}, and no courier reports which items it is holding. "
            .'Until somebody says, the units on this parcel are neither back in stock nor written off — so tick what came back and the shelf is squared at what those units cost when they left.';
    }

    /**
     * The parcel of this Order that half arrived and is waiting on an answer,
     * if there is one.
     *
     * The newest such attempt: an Order sent twice can have two, and the one
     * a User is being asked about is the one that just happened.
     */
    private static function halfArrived(Order $record): ?Shipment
    {
        return $record->shipments()->awaitingAnswer()->latest('id')->first();
    }

    private static function sayThereIsNothingToAnswer(): void
    {
        Notification::make()
            ->title('Nothing to answer')
            ->body('This parcel was answered while this page was open. Nothing was written twice.')
            ->warning()
            ->send();
    }
}
