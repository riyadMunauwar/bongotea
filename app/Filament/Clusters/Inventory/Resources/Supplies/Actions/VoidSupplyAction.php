<?php

namespace App\Filament\Clusters\Inventory\Resources\Supplies\Actions;

use App\Enums\Permission;
use App\Models\Supply;
use App\Models\SupplyLine;
use App\Support\Inventory\Grams;
use App\Support\Inventory\StockLedger;
use App\Support\Inventory\SupplyAlreadyVoided;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * "Void this delivery": the one thing a User may do to a Supply after it is
 * recorded, and the escape hatch the whole ledger rests on.
 *
 * Somebody will type 500 where they meant 50, and the ledger has to survive
 * it without being edited. Voiding writes the exact reversing movements,
 * marks the document with a time and a name, and leaves both on the record —
 * which is why there is no edit action anywhere near this screen and no
 * delete one either.
 *
 * Offered from the document and never from the list, the way abandoning a
 * parcel is offered from the Order rather than from the Shipment. What is
 * about to be taken back out is on the screen the button is on: a User
 * voiding the wrong row of a list of eight deliveries from the same Supplier
 * would be answering a mistake with a larger one.
 *
 * The confirmation says what will happen in units rather than in the word
 * "reverse", because the figure a Merchant is about to watch move is the one
 * on their own shelf.
 *
 * Gated on `Permission::VoidSupplies`, a rung above the `count stock` that
 * opens these screens: a User who records deliveries reaches the document, but
 * only one the Merchant trusts with the ledger may strike one out. The gate
 * sits on the action's own visibility, so it is neither drawn nor mountable
 * without the Permission, and it stacks with the void-once rule rather than
 * replacing it.
 */
class VoidSupplyAction
{
    public static function make(): Action
    {
        return Action::make('voidSupply')
            ->label('Void this delivery')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')

            /**
             * Two reasons it may not be drawn, and a hidden Filament action
             * cannot be mounted either, so this is the refusal as well as the
             * absence. Held back behind `void supplies` — removing a Supply
             * from the ledger and moving every cost blended off it is not
             * something the person who records deliveries all day can reach —
             * and voided once: a struck-out delivery has nothing left to take
             * back out, and the screen says so instead.
             */
            ->visible(fn (Supply $record): bool => (auth()->user()?->can(Permission::VoidSupplies) ?? false)
                && ! $record->isVoided())
            ->requiresConfirmation()
            ->modalHeading('Void this delivery?')
            ->modalDescription(fn (Supply $record): string => self::whatWillHappen($record))
            ->modalSubmitActionLabel('Void it')
            ->action(function (Supply $record, StockLedger $ledger): void {
                try {
                    /**
                     * The ledger re-reads this under the Supply's own lock, so
                     * the screen being a minute old is its problem rather than
                     * ours: a colleague who voided it in between is answered
                     * there, and told about here.
                     */
                    $ledger->voidSupply($record, auth()->user());
                } catch (SupplyAlreadyVoided) {
                    Notification::make()
                        ->title('Already voided')
                        ->body('This delivery was voided while this page was open. Nothing was written twice.')
                        ->warning()
                        ->send();

                    return;
                }

                $record->refresh();

                Notification::make()
                    ->title('Delivery voided')
                    ->body('Every unit on it has come back out at what it came in at, and the delivery stays on the record with your name against it.')
                    ->success()
                    ->send();
            });
    }

    /**
     * What the User is about to do, in units of their own Products.
     *
     * Every line is named rather than counted, because the case this exists
     * for is a quantity that is wrong by a factor of ten — and the moment to
     * notice that the wrong delivery is open is while reading the numbers
     * that are about to move.
     */
    private static function whatWillHappen(Supply $record): string
    {
        $lines = $record->lines()
            ->with('product')
            ->get()
            ->map(fn (SupplyLine $line): string => Grams::toKilograms($line->quantity)." × {$line->product->name}")
            ->join(', ', ' and ');

        return "This takes {$lines} back out of stock, at what they came in at — "
            .'on hand for each goes back to exactly what it was before this delivery was recorded. '
            .'The delivery itself is not deleted: it stays on the record at '
            .Money::format($record->totalCost())
            .', so the mistake and its correction can be read together, and your name and the time are written against it.';
    }
}
