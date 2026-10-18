<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\Permission;
use App\Filament\Resources\Orders\Actions\AbandonShipmentAction;
use App\Filament\Resources\Orders\Actions\AnswerPartlyDeliveredAction;
use App\Filament\Resources\Orders\Actions\BookWithCourierAction;
use App\Filament\Resources\Orders\Actions\CheckWithGatewayAction;
use App\Filament\Resources\Orders\Actions\SwitchToCashOnDeliveryAction;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Orders\BeyondFreeToSell;
use App\Support\Orders\Repricing;
use App\Support\Orders\RetailRepricing;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
             *
             * Booking is the one exception, and the `after()` is what pays for
             * it. The send dialog may carry a correction — `ParcelDetails::corrections()`
             * commits `customer_name`, `customer_address` and `customer_district`
             * to the **Order** before the parcel goes, and all three are fields
             * on `OrderForm`. Livewire's `$data` was filled when the page
             * opened and knows nothing of a write made under it, so without
             * this an operator who fixes a mistyped address in the dialog and
             * then presses Save puts the typo straight back — and the next
             * re-send after a return uses it.
             *
             * Only here: the action is one class on three screens, and the
             * other two hold no form to go stale. `ViewOrder` draws an
             * infolist, and the queue is a table.
             */
            BookWithCourierAction::make()
                ->after(fn () => $this->refreshFormData(['customer_name', 'customer_address', 'customer_district'])),
            AbandonShipmentAction::make(),
            AnswerPartlyDeliveredAction::make(),
            CheckWithGatewayAction::make(),
            SwitchToCashOnDeliveryAction::make(),

            ViewAction::make(),

            /**
             * Soft delete. A deleted Order is one that should never have
             * existed; an Order that happened but will not be fulfilled is
             * `cancelled`, and conflating the two makes real conversion
             * unknowable.
             *
             * Deleting, force-deleting and restoring each need `delete orders`
             * on top of the `work orders` floor, so the queue cannot be tidied
             * away by whoever is working it. Gated with `authorize()` rather
             * than the actions' own `visible()`/`hidden()` so the Permission
             * check composes with the record-state rules Filament already puts
             * on them — delete hidden on a trashed row, restore and force-delete
             * shown only on one — and a User without it sees none of the three.
             */
            DeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can(Permission::DeleteOrders) ?? false),
            ForceDeleteAction::make()
                ->authorize(fn (): bool => auth()->user()?->can(Permission::DeleteOrders) ?? false),
            RestoreAction::make()
                ->authorize(fn (): bool => auth()->user()?->can(Permission::DeleteOrders) ?? false),
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

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['lines'] = $this->getRecord()->isRetail() ? RetailRepricing::stateOf($this->getRecord()) : Repricing::stateOf($this->getRecord());

        return $data;
    }

    /**
     * The Order's details are saved as they are; its lines only when they
     * changed, and then the whole Order is repriced at today's prices in the
     * same transaction. A District changed reprices it too, because it moves
     * the Order into another Delivery Zone (ADR-0039). Untouched lines keep
     * what was agreed at Capture. An Order with a Shipment sends no lines at
     * all — the form holds them shut — and `Repricing` refuses it besides.
     *
     * @param  Order  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $lines = $data['lines'] ?? null;
        $beyondStockReason = $data[OrderForm::BEYOND_STOCK_REASON] ?? null;
        unset($data['lines'], $data['kind'], $data[OrderForm::BEYOND_STOCK_REASON]);

        return DB::transaction(function () use ($record, $data, $lines, $beyondStockReason): Order {
            $record->update($data);

            if (! is_array($lines)) {
                return $record;
            }

            $districtMoved = $record->wasChanged('customer_district');

            try {
                if ($record->isRetail() && ($districtMoved || RetailRepricing::changes($record, $lines))) {
                    app(RetailRepricing::class)->apply($record, $lines, auth()->user(), $beyondStockReason);
                } elseif (! $record->isRetail() && ($districtMoved || Repricing::changes($record, $lines))) {
                    app(Repricing::class)->apply($record, $lines, auth()->user(), $beyondStockReason);
                }
            } catch (BeyondFreeToSell $refusal) {
                $this->refuseBeyondStock($refusal);
            }

            return $record;
        });
    }

    /**
     * A stock refusal the form's own check could not see coming — stock taken
     * by another Order between the check and the save, or a status changed in
     * the same save — shown as a refusal, with nothing written.
     */
    private function refuseBeyondStock(BeyondFreeToSell $refusal): void
    {
        Notification::make()
            ->danger()
            ->title('Not enough is free to sell')
            ->body($refusal->getMessage())
            ->send();

        $this->halt(shouldRollbackDatabaseTransaction: true);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
