<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\Actions\BookWithCourierAction;
use App\Filament\Resources\Orders\Actions\CheckWithGatewayAction;
use App\Filament\Resources\Orders\Actions\SwitchToCashOnDeliveryAction;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Customer;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /**
             * The payment and parcel axes, worked from the header rather than
             * the form: the form holds neither `payment_method` nor anything
             * of a Shipment's, so a save after any of these actions cannot
             * write stale data back over what they wrote.
             */
            BookWithCourierAction::make(),
            CheckWithGatewayAction::make(),
            SwitchToCashOnDeliveryAction::make(),

            ViewAction::make(),

            /**
             * Soft delete. A deleted Order is one that should never have
             * existed; an Order that happened but will not be fulfilled is
             * `cancelled`, and conflating the two makes real conversion
             * unknowable.
             */
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    /**
     * Keep the Order pointed at the Customer who owns the phone number it
     * carries.
     *
     * A Customer *is* their phone number, so a corrected number is not a
     * corrected attribute — it is a different identity, and an Order left
     * linked to the old one would claim a person placed an order they never
     * did. `Customer::forPhone` is the only thing that resolves a number, so
     * the canonical form is written back here too: an operator typing
     * `01712-345678` must not fork the identity that `+8801712345678` already
     * has.
     *
     * The Customer left behind is not cleaned up. A customer with no orders is
     * harmless; deleting one that a second operator is mid-edit on is not.
     *
     * An untouched number is left alone: it is already canonical and already
     * linked, and re-resolving it under phone rules that have changed since
     * Capture would refuse a save that changed nothing about the Customer.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['customer_phone'] ?? null) === $this->record->customer_phone) {
            return $data;
        }

        /** The field's own rule already rejected anything unresolvable. */
        $customer = Customer::forPhone((string) $data['customer_phone']);

        $data['customer_id'] = $customer->getKey();
        $data['customer_phone'] = $customer->phone;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
