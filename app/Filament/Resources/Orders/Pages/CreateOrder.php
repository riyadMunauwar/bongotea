<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderKind;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Orders\Repricing;
use App\Support\Orders\RetailRepricing;
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
        unset($data['lines']);

        $customer = Customer::forPhone((string) $data['customer_phone']);

        return DB::transaction(function () use ($customer, $data, $lines): Order {
            $order = $customer->orders()->create([
                ...$data,
                'kind' => $data['kind'] ?? OrderKind::Bulk,
                'customer_phone' => $customer->phone,
                'payment_method' => PaymentMethod::CashOnDelivery,
            ]);

            $order->isRetail()
                ? app(RetailRepricing::class)->apply($order, $lines, auth()->user())
                : app(Repricing::class)->apply($order, $lines, auth()->user());

            return $order->refresh();
        });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
