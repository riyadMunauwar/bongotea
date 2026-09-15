<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\Actions\AbandonShipmentAction;
use App\Filament\Resources\Orders\Actions\AnswerPartlyDeliveredAction;
use App\Filament\Resources\Orders\Actions\BookWithCourierAction;
use App\Filament\Resources\Orders\Actions\CheckWithGatewayAction;
use App\Filament\Resources\Orders\Actions\SwitchToCashOnDeliveryAction;
use App\Filament\Resources\Orders\OrderResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    /**
     * What an operator does about an Order on the call — the money, and
     * getting the parcel moving — offered on both screens so nobody has to
     * open the editor to resolve a payment or send a parcel.
     *
     * Sending and abandoning sit together and never appear together: one is
     * offered to an Order with no parcel out, the other to an Order with one,
     * and the pair is the whole of what a User may decide about a parcel.
     *
     * Answering a half-arrived parcel sits beside them and belongs to neither
     * pair: it decides nothing about the Order, it says what became of the
     * box (ADR-0021), and it is the only thing that takes the Order off Needs
     * Attention for it.
     */
    protected function getHeaderActions(): array
    {
        return [
            BookWithCourierAction::make(),
            AbandonShipmentAction::make(),
            AnswerPartlyDeliveredAction::make(),
            CheckWithGatewayAction::make(),
            SwitchToCashOnDeliveryAction::make(),
            EditAction::make(),
        ];
    }
}
