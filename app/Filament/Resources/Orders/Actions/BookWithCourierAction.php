<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\Courier;
use App\Models\Order;
use App\Models\Shipment;
use App\Support\Couriers\BookingNotMade;
use App\Support\Couriers\CourierBooker;
use App\Support\Couriers\CourierNotUsable;
use App\Support\Couriers\CourierUnreachable;
use App\Support\Couriers\OrderAlreadyShipping;
use App\Support\Money;
use App\Support\Settings\CourierSettings;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * "Send with courier": a User books this Order's parcel and watches the
 * Consignment come back.
 *
 * A header action on both the reading and the working screen, never a field
 * on the form (ADR-0018's separation, the precedent the payment actions set):
 * the parcel is its own axis, and a save after a booking must not be able to
 * write stale data back over what the Courier said.
 *
 * The Courier is asked inside the request and the answer is shown at once —
 * the Consignment and the tracking code, which is the entire reason a single
 * booking is not swept (ADR-0020). A Courier that says no is worded back in
 * its own words with nothing recorded; a Courier that cannot be reached
 * leaves the Order exactly as it was and says to try again.
 */
class BookWithCourierAction
{
    public static function make(): Action
    {
        return Action::make('bookWithCourier')
            ->label('Send with courier')
            ->icon(Heroicon::OutlinedTruck)
            ->color('primary')

            /**
             * Offered while a parcel may be sent: this installation has a
             * usable Courier, and nothing is already out for the Order. The
             * booking asks both again under the Order's lock, because a
             * screen is only as fresh as the moment it was drawn.
             */
            ->visible(fn (Order $record): bool => filled(self::couriers()) && ! $record->isShipping())
            ->schema([
                Select::make('courier')
                    ->label('Courier')
                    ->options(self::couriers(...))
                    ->default(fn (): ?string => array_key_first(self::couriers()))
                    ->selectablePlaceholder(false)
                    ->required(),
            ])
            ->modalHeading('Send this order with a courier')
            ->modalDescription(fn (Order $record): string => self::whatWillBeCollected($record))
            ->modalSubmitActionLabel('Send')
            ->action(function (Order $record, array $data, CourierBooker $booker): void {
                $courier = Courier::fromStored($data['courier'] ?? null);

                if ($courier === null) {
                    return;
                }

                try {
                    $shipment = $booker->book($record, $courier);
                } catch (OrderAlreadyShipping $refusal) {
                    self::refuse('Already with a courier', $refusal->getMessage());

                    return;
                } catch (CourierUnreachable) {
                    self::refuse("{$courier->getLabel()} could not be reached", 'Nothing was sent and the order is unchanged. Try again in a moment.');

                    return;
                } catch (CourierNotUsable $unusable) {
                    /** Never worded as a refusal: nobody asked this Courier anything, and the answer is a Merchant's to give in Settings. */
                    self::refuse("{$courier->getLabel()} cannot be used", $unusable->getMessage());

                    return;
                } catch (BookingNotMade $refusal) {
                    self::refuse("{$courier->getLabel()} refused this parcel", $refusal->getMessage());

                    return;
                }

                $record->refresh();

                self::announce($shipment);
            });
    }

    /**
     * The couriers a parcel can actually be handed to on this installation,
     * by name, for the chooser. One that is switched off or half-supplied is
     * not offered at all, and why is said in Settings rather than restated
     * here.
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

    /**
     * What the Courier will be told to collect, said before the parcel goes
     * rather than discovered afterwards: nothing where money has already
     * moved, the Order's total — Delivery Charge included — otherwise.
     */
    private static function whatWillBeCollected(Order $record): string
    {
        return $record->amountDueOnDelivery() === 0
            ? 'This order is paid, so the courier will be told to collect nothing.'
            : 'The courier will be told to collect '.Money::format($record->amountDueOnDelivery()).' at the door, the delivery charge included.';
    }

    /**
     * The Consignment and the tracking code, in front of the User who asked
     * for them, while the Customer is still on the phone.
     */
    private static function announce(Shipment $shipment): void
    {
        $tracking = $shipment->tracking_code === null ? '' : " Tracking code {$shipment->tracking_code}.";

        Notification::make()
            ->title("Sent with {$shipment->courier->getLabel()}")
            ->body("Consignment {$shipment->consignment_reference}.{$tracking}")
            ->success()
            ->send();
    }

    /** Nothing was sent, and the reason is the Courier's own wording wherever there is one. */
    private static function refuse(string $title, string $reason): void
    {
        Notification::make()
            ->title($title)
            ->body($reason)
            ->danger()
            ->send();
    }
}
