<?php

namespace App\Support\Pages\Blocks;

use App\Enums\OrderProgress;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Support\Pages\PageBlock;
use App\Support\PhoneNumber;
use App\Support\Settings\GeneralSettings;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * A live Block: Signing in with a One-Time Code for a stranger, and for a
 * Customer Signed in every Order ever placed with their number — those from
 * before they first signed in among them — newest first, each with its
 * Order Progress. The Merchant writes only the wording.
 *
 * Read off the Customer's row by number, never off `orders.customer_phone`,
 * so an Order a User moved to another number follows the number it is under
 * now. Dated by the Merchant's day, not the server's. Figures go in minor
 * units and Kilograms; no Shipment detail beyond the
 * Progress worked out from it is ever sent.
 */
class MyOrders extends PageBlock
{
    public static function type(): string
    {
        return 'my_orders';
    }

    public static function label(): string
    {
        return 'My orders';
    }

    public static function icon(): Heroicon
    {
        return Heroicon::OutlinedClipboardDocumentList;
    }

    public static function fields(): array
    {
        return [
            TextInput::make('heading')->maxLength(160),
            TextInput::make('text')->maxLength(300),
        ];
    }

    public static function props(array $data): array
    {
        /** @var Customer|null $customer */
        $customer = Auth::guard('customer')->user();

        return [
            'heading' => self::text($data, 'heading'),
            'text' => self::text($data, 'text'),
            'phone' => $customer === null ? null : PhoneNumber::asDialled($customer->phone),
            'orders' => $customer === null || ! $customer->exists ? [] : self::ordersOf($customer),
        ];
    }

    /**
     * @return list<array{number: string, placedOn: string, lines: list<array{name: string, kilograms: int}>, kilograms: int, total: int, progress: string}>
     */
    private static function ordersOf(Customer $customer): array
    {
        $timezone = app(GeneralSettings::class)->timezone;

        return $customer->orders()
            ->with(['lines', 'shipments'])
            ->latest()
            ->latest('id')
            ->get()
            ->map(fn (Order $order): array => [
                'number' => $order->order_number,
                'placedOn' => $order->created_at->setTimezone($timezone)->toDateString(),
                'lines' => $order->lines
                    ->map(fn (OrderLine $line): array => ['name' => $line->product_name, 'kilograms' => $line->quantity])
                    ->values()
                    ->all(),
                'kilograms' => $order->total_kilograms,
                'total' => $order->total,
                'progress' => OrderProgress::of($order)->value,
            ])
            ->values()
            ->all();
    }
}
