<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Support\Money;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * What may be changed about an Order after Capture.
 *
 * Filament hands you full-field editing by default, so everything agreed at
 * Capture has to be *actively* held shut here rather than assumed immutable.
 * What was agreed is what gets collected at the door: a price edited after the
 * fact means a fulfiller asking for a figure the customer never accepted, and
 * in COD that is a refused parcel and a courier fee.
 */
class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Fulfilment')
                    ->description('The confirmation call is the COD workflow. Mark what happened so the same customer is not called twice, or missed.')
                    ->schema([
                        Select::make('status')
                            ->options(OrderStatus::class)
                            ->required()
                            ->native(false)
                            ->helperText('Cancelled means it genuinely happened but will not be fulfilled — an unreachable number, a changed mind, a refused parcel.'),
                    ]),

                Section::make('Deliver to')
                    ->columns(2)
                    ->schema([
                        TextInput::make('customer_name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),

                        /**
                         * Read-only: the phone number *is* the Customer. Editing
                         * it here would leave the order's snapshot disagreeing
                         * with the identity it is linked to, and re-pointing the
                         * link is merge semantics this system has not designed.
                         * A number that cannot be reached is what `cancelled` is
                         * for.
                         */
                        TextInput::make('customer_phone')
                            ->label('Phone')
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('A customer is their phone number, so this cannot be edited. Cancel the order if the number does not reach anyone.'),

                        Select::make('customer_district')
                            ->label('District')
                            ->options(fn (): array => array_combine(
                                config('giopages.districts'),
                                config('giopages.districts'),
                            ))
                            ->required()
                            ->searchable()
                            ->native(false),

                        TextInput::make('delivery_charge')
                            ->label('Delivery charge ('.config('giopages.currency.code').')')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->prefix(config('giopages.currency.symbol'))
                            ->helperText('Editable, because what the courier charges is agreed on the call. The total follows automatically.')
                            ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Money::toMajorString($state))
                            ->dehydrateStateUsing(fn (string $state): int => Money::toMinorUnits($state)),

                        Textarea::make('customer_address')
                            ->label('Address')
                            ->required()
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),

                Section::make('Items')
                    ->description('Quantity is editable; the price is not. Totals recompute from the price captured with the order, never from the product\'s price today.')
                    ->schema([
                        Repeater::make('lines')
                            ->relationship()
                            ->hiddenLabel()
                            ->columns(3)
                            /** An Order's shape was fixed at Capture. Only its numbers move. */
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->schema([
                                TextInput::make('product_name')
                                    ->label('Product')
                                    ->disabled()
                                    ->dehydrated(false),

                                TextInput::make('unit_price')
                                    ->label('Unit price')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Money::format($state)),

                                TextInput::make('quantity')
                                    ->label('Qty')
                                    ->required()
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(config('giopages.checkout.max_quantity')),
                            ]),
                    ]),
            ]);
    }
}
