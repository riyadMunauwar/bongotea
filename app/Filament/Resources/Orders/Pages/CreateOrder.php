<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderKind;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Orders\BeyondFreeToSell;
use App\Support\Orders\Repricing;
use App\Support\Orders\RetailRepricing;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * An Order taken by phone: Capture by a User rather than by the Checkout.
 *
 * Priced live as the User types by the pricing module the storefront's Cart
 * answers to (the Checkout's Capture moves onto it with the Quote, #15). The
 * Customer is resolved from
 * the phone number exactly as Capture resolves one, and the Order is Cash on
 * Delivery, because nobody pays online over a phone call. Its kind is chosen
 * first and priced accordingly: Products and Kilograms for bulk, Packs for
 * retail (ADR-0034).
 */
class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    /**
     * Resolved outside the transaction, as Capture does: `Customer::forPhone`
     * recovers from a unique-constraint race by re-querying, which a failed
     * insert inside an open transaction would not allow on PostgreSQL.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $lines = $data['lines'] ?? [];
        $beyondStockReason = $data[OrderForm::BEYOND_STOCK_REASON] ?? null;
        unset($data['lines'], $data[OrderForm::BEYOND_STOCK_REASON]);

        $customer = Customer::forPhone((string) $data['customer_phone']);

        return DB::transaction(function () use ($customer, $data, $lines, $beyondStockReason): Order {
            $order = $customer->orders()->create([
                ...$data,
                'kind' => $data['kind'] ?? OrderKind::Bulk,
                'customer_phone' => $customer->phone,
                'payment_method' => PaymentMethod::CashOnDelivery,
            ]);

            try {
                $order->isRetail()
                    ? app(RetailRepricing::class)->apply($order, $lines, auth()->user(), $beyondStockReason)
                    : app(Repricing::class)->apply($order, $lines, auth()->user(), $beyondStockReason);
            } catch (BeyondFreeToSell $refusal) {
                $this->refuseBeyondStock($refusal);
            }

            return $order->refresh();
        });
    }

    /**
     * A stock refusal the form's own check could not see coming — stock taken
     * by another Order between the check and the save — shown as a refusal,
     * with no Order written.
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
