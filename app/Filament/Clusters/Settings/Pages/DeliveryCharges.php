<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Support\Money;
use App\Support\Pricing\KilogramRanges;
use App\Support\Settings\DeliveryChargeSettings;
use App\Support\Settings\GeneralSettings;
use BackedEnum;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

/**
 * The Delivery Bands (ADR-0031): a list of kilogram ranges that must cover
 * every whole kilogram from one upwards without gap or overlap, the last
 * open-ended; the page refuses to save anything else, so every Cart weight has
 * exactly one Delivery Charge. Tiers are not set here: each Product owns its
 * own, on the Product.
 */
class DeliveryCharges extends SettingsPage
{
    protected static string $group = DeliveryChargeSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    /** After the store's identity: what it charges to deliver. */
    protected static ?int $navigationSort = 2;

    /**
     * @return array<Section>
     */
    protected function formComponents(): array
    {
        return [
            Section::make('Delivery Bands')
                ->description('The Delivery Charge, by the cart\'s total kilograms, the same across the country. Each Product sets its own Tiers; where a band ends says nothing about where a price changes.')
                ->schema([
                    Repeater::make('deliveryBands')
                        ->hiddenLabel()
                        ->addActionLabel('Add a band')
                        ->defaultItems(0)
                        ->columns(3)
                        ->rules([self::coversEveryKilogram('band')])
                        ->schema([
                            ...self::rangeFields(),

                            /** Whole taka on screen, minor units in the row, like every other amount. */
                            TextInput::make('fee')
                                ->label('Delivery Charge')
                                ->required()
                                ->integer()
                                ->minValue(0)
                                ->prefix(fn (): string => app(GeneralSettings::class)->currencySymbol)
                                ->helperText('0 is free delivery.')
                                ->formatStateUsing(fn (mixed $state): mixed => is_numeric($state) ? Money::toWholeMajorString((int) $state) : $state)
                                ->dehydrateStateUsing(fn (mixed $state): int => Money::toMinorUnits((int) $state)),
                        ]),
                ]),

            Section::make('Retail delivery')
                ->description('What a packet basket pays for delivery: one flat charge, owing nothing to the Delivery Bands above, waived once its subtotal reaches the threshold.')
                ->columns(2)
                ->schema([
                    self::takaField('retailDeliveryCharge')
                        ->label('Retail Delivery Charge')
                        ->required(),

                    self::takaField('retailFreeDeliveryFrom')
                        ->label('Free delivery from')
                        ->helperText('Leave empty and retail delivery is never free.')
                        ->dehydrateStateUsing(fn (mixed $state): ?int => $state === null || $state === '' ? null : Money::toMinorUnits((int) $state)),
                ]),
        ];
    }

    /** Whole taka on screen, minor units in the row, like every other amount. */
    private static function takaField(string $name): TextInput
    {
        return TextInput::make($name)
            ->integer()
            ->minValue(0)
            ->prefix(fn (): string => app(GeneralSettings::class)->currencySymbol)
            ->formatStateUsing(fn (mixed $state): mixed => is_numeric($state) ? Money::toWholeMajorString((int) $state) : $state)
            ->dehydrateStateUsing(fn (mixed $state): int => Money::toMinorUnits((int) $state));
    }

    /**
     * @return list<TextInput>
     */
    private static function rangeFields(): array
    {
        return [
            TextInput::make('min')
                ->label('From')
                ->required()
                ->integer()
                ->minValue(1)
                ->suffix('kg'),

            TextInput::make('max')
                ->label('To')
                ->integer()
                ->minValue(1)
                ->suffix('kg')
                ->helperText('Blank on the last one, for "and over".'),
        ];
    }

    /**
     * The table as a whole covers every kilogram once, raised on the list
     * rather than on a row, because a gap is between two rows and belongs to
     * neither.
     */
    private static function coversEveryKilogram(string $noun): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($noun): void {
            $problem = KilogramRanges::problem(is_array($value) ? $value : [], $noun);

            if ($problem !== null) {
                $fail($problem);
            }
        };
    }
}
