<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\Courier;
use App\Models\Order;
use App\Support\Couriers\BatchBooker;
use App\Support\Couriers\BatchWritten;
use App\Support\Couriers\CourierNotUsable;
use App\Support\Settings\CourierSettings;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * "Send all with courier": a morning's Orders go out in one action.
 *
 * A User narrows the queue to the set they mean — today's New Orders, or a
 * date range — ticks them, chooses one Courier once, and the whole set is
 * accepted immediately and worked through by `couriers:sweep` (ADR-0020).
 * Nothing is dispatched while they wait: two hundred parcels is two hundred
 * sequential calls for a Courier with no bulk endpoint, and no web request
 * survives that.
 *
 * There is deliberately **no header action of its own with status and date
 * pickers on it.** The list's tabs and its captured-between filter already
 * express that, in the Merchant's timezone and in agreement with every report
 * — and a second control for the same axis could quietly dispatch a set of
 * real parcels that is not the set the User was looking at. The selection is
 * the set, and the selection is what the screen shows.
 *
 * What the batch will do is said before it commits and what it did is said
 * after. An Order already holding an Active Shipment is never in it, which is
 * the invariant that stops a Merchant paying for two parcels; an Order whose
 * delivery area a Courier insists on and nothing could match is named back as
 * needing one rather than sent to a guessed depot.
 */
class BookBatchWithCourierAction
{
    /** How many Orders a report names before it stops naming them and counts the rest. */
    private const NAMED_AT_MOST = 5;

    public static function make(): BulkAction
    {
        return BulkAction::make('bookBatchWithCourier')
            ->label('Send with courier')
            ->icon(Heroicon::OutlinedTruck)
            ->color('primary')

            /** Offered while this installation has a Courier to hand a parcel to; why it has none is said in Settings. */
            ->visible(fn (): bool => filled(self::couriers()))
            ->modalHeading('Send these orders with a courier')
            ->modalDescription(fn (Collection $records): string => self::whatWillBeSent($records))
            ->modalSubmitActionLabel('Send')
            ->modalWidth(Width::Large)
            ->schema([
                Select::make('courier')
                    ->label('Courier')
                    ->helperText('Every parcel in this batch goes with the same courier, on the settings you chose for it.')
                    ->options(self::couriers(...))
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->action(function (Collection $records, array $data, BatchBooker $booker): void {
                $courier = Courier::fromStored($data['courier'] ?? null);

                if ($courier === null) {
                    return;
                }

                try {
                    /** @var iterable<Order> $orders */
                    $orders = $records;

                    $batch = $booker->write($orders, $courier);
                } catch (CourierNotUsable $unusable) {
                    /** Never worded as a refusal: no Courier was asked anything, and the answer is a Merchant's to give in Settings. */
                    Notification::make()
                        ->title("{$courier->getLabel()} cannot be used")
                        ->body($unusable->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                self::announce($batch, $courier);
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * What this batch will do, in front of the User before they commit to it:
     * how many parcels go, and how many of the Orders they ticked are passed
     * over because one is already out for them.
     *
     * Said here rather than discovered afterwards, because the set dispatched
     * has to be the set they were looking at. The count is honest about only
     * what can be known without choosing a Courier — a parcel whose delivery
     * area could not be matched depends on which Courier is chosen, and is
     * named back by the batch itself.
     *
     * @param  Collection<int, Order>  $records
     */
    private static function whatWillBeSent(Collection $records): string
    {
        $shipping = $records->filter(fn (Order $order): bool => $order->isShipping())->count();
        $sending = $records->count() - $shipping;

        $sentence = $sending === 0
            ? 'None of these orders can be sent.'
            : "{$sending} ".Str::plural('parcel', $sending).' will be booked, one for each order.';

        if ($shipping === 0) {
            return $sentence;
        }

        return $sentence." {$shipping} ".Str::plural('order', $shipping)
            .' will be skipped: a parcel is already out for '.($shipping === 1 ? 'it' : 'them').'.';
    }

    /**
     * What the batch actually committed, and everything it deliberately left
     * alone.
     *
     * The Orders needing a delivery area are named rather than counted: a User
     * has to open each of them and send it themselves, and a bare number is a
     * search they cannot make.
     */
    private static function announce(BatchWritten $batch, Courier $courier): void
    {
        $written = $batch->count();

        if ($written === 0) {
            Notification::make()
                ->title('Nothing was sent')
                ->body(self::whatWasLeft($batch))
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title("{$written} ".Str::plural('parcel', $written)." booked with {$courier->getLabel()}")
            ->body('They go out on the next sweep, within a few minutes. '.self::whatWasLeft($batch))
            ->success()
            ->send();
    }

    /** Every Order the batch passed over, each kind in its own words. */
    private static function whatWasLeft(BatchWritten $batch): string
    {
        $sentences = [];

        if (($shipping = count($batch->alreadyShipping)) > 0) {
            $sentences[] = "{$shipping} ".Str::plural('order', $shipping).' skipped: a parcel is already out for '.($shipping === 1 ? 'it' : 'them').'.';
        }

        if ($batch->needingAnArea !== []) {
            $sentences[] = 'Needs a delivery area, so not sent: '.self::naming($batch->needingAnArea).'.';
        }

        if ($batch->deleted !== []) {
            $sentences[] = 'Deleted, so not sent: '.self::naming($batch->deleted).'.';
        }

        return $sentences === [] ? 'Nothing was skipped.' : implode(' ', $sentences);
    }

    /**
     * These Orders by their own numbers, cut short once there are more of them
     * than anybody reads off a notification.
     *
     * @param  list<Order>  $orders
     */
    private static function naming(array $orders): string
    {
        $numbers = array_map(fn (Order $order): string => $order->order_number, $orders);
        $rest = count($numbers) - self::NAMED_AT_MOST;

        if ($rest <= 0) {
            return implode(', ', $numbers);
        }

        return implode(', ', array_slice($numbers, 0, self::NAMED_AT_MOST))." and {$rest} more";
    }

    /**
     * The couriers a parcel can actually be handed to on this installation,
     * by name. One that is switched off or half-supplied is not offered at
     * all, and why is said in Settings rather than restated here.
     *
     * @return array<string, string>
     */
    private static function couriers(): array
    {
        $couriers = [];

        foreach (app(CourierSettings::class)->usableCouriers() as $courier) {
            $couriers[$courier->value] = $courier->getLabel();
        }

        return $couriers;
    }
}
