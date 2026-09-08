<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\Actions\CheckWithGatewayAction;
use App\Filament\Resources\Orders\Actions\SwitchToCashOnDeliveryAction;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    /**
     * The two things an operator does about the money on the call, offered
     * on both screens so nobody has to open the editor to resolve a payment.
     */
    protected function getHeaderActions(): array
    {
        return [
            CheckWithGatewayAction::make(),
            SwitchToCashOnDeliveryAction::make(),
            EditAction::make(),
        ];
    }
}
