<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The job a System Page does, which is how the store finds it — never by its
 * title or its Slug. A Page holding one can be neither deleted nor given
 * another, so whatever links to "the bulk order page" always finds one.
 */
enum SystemPage: string implements HasLabel
{
    case Home = 'home';
    case BulkOrder = 'bulk_order';
    case MyOrders = 'my_orders';
    case PacketBasket = 'packet_basket';

    public function getLabel(): string
    {
        return match ($this) {
            self::Home => 'Home',
            self::BulkOrder => 'Bulk Order',
            self::MyOrders => 'My Orders',
            self::PacketBasket => 'Packet Basket',
        };
    }
}
