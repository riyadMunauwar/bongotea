<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Support\Money;
use App\Support\Pricing\DeliveryZoneRules;
use App\Support\Pricing\KilogramRanges;
use App\Support\Settings\DeliveryChargeSettings;
use App\Support\Settings\GeneralSettings;
use BackedEnum;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;

/**
 * Bulk Delivery Charges and Retail Delivery Charges (ADR-0039): each a list of
 * Delivery Zones, every zone a name, the Districts it covers and its kilogram
 * ranges, and exactly one zone "everywhere else" so a District no zone lists
 * is still charged. The ranges of each zone must cover every whole kilogram
 * from one upwards without gap or overlap, the last open-ended; the page
 * refuses to save anything else, so every Order has exactly one Delivery
 * Charge. A retail zone may also say from what subtotal its delivery is free.
 * Tiers are not set here: each Product owns its own, on the Product.
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
            Section::make('Bulk Delivery Charges')
                ->description('What a bulk order pays for delivery, by the zone its District falls in and the cart\'s total kilograms. Each Product sets its own Tiers; where a range ends says nothing about where a price changes.')
                ->schema([self::zones('bulkZones', hasThreshold: false)]),

            Section::make('Retail Delivery Charges')
                ->description('What a packet order pays for delivery, by the zone its District falls in and its packs\' weight, rounded up to the next whole kilogram. It owes nothing to the bulk charges above.')
                ->schema([self::zones('retailZones', hasThreshold: true)]),
        ];
    }

    private static function zones(string $name, bool $hasThreshold): Repeater
    {
        return Repeater::make($name)
            ->hiddenLabel()
            ->addActionLabel('Add a zone')
            ->defaultItems(0)
            ->collapsible()
            ->itemLabel(fn (array $state): ?string => filled($state['name'] ?? null) ? $state['name'] : null)
            ->rules([self::zonesHold()])
            ->schema([
                TextInput::make('name')
                    ->label('Zone')
                    ->required()
                    ->maxLength(80)
                    ->placeholder('Inside Dhaka'),

                Toggle::make('everywhereElse')
                    ->label('Everywhere else')
                    ->helperText('Every District no other zone lists. Exactly one zone is.')
                    ->live(),

                Select::make('districts')
                    ->label('Districts')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => array_combine(config('giopages.districts'), config('giopages.districts')))
                    ->required(fn (Get $get): bool => ! $get('everywhereElse'))
                    ->hidden(fn (Get $get): bool => (bool) $get('everywhereElse')),

                Repeater::make('bands')
                    ->label('Kilogram ranges')
                    ->addActionLabel('Add a range')
                    ->defaultItems(1)
                    ->columns(3)
                    ->rules([self::coversEveryKilogram()])
                    ->schema([
                        ...self::rangeFields(),

                        self::takaField('fee')
                            ->label('Delivery Charge')
                            ->required()
                            ->helperText('0 is free delivery.'),
                    ]),

                ...($hasThreshold ? [
                    self::takaField('freeDeliveryFrom')
                        ->label('Free delivery from')
                        ->helperText('Leave empty and delivery to this zone is never free.')
                        ->dehydrateStateUsing(fn (mixed $state): ?int => $state === null || $state === '' ? null : Money::toMinorUnits((int) $state)),
                ] : []),
            ]);
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
     * A zone's ranges as a whole cover every kilogram once, raised on the
     * list rather than on a row, because a gap is between two rows and
     * belongs to neither.
     */
    private static function coversEveryKilogram(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            $problem = KilogramRanges::problem(is_array($value) ? $value : []);

            if ($problem !== null) {
                $fail($problem);
            }
        };
    }

    /**
     * The zones as a whole: one name each, one of them everywhere else, and
     * no District in two — raised on the list, because a District listed
     * twice is in two rows and belongs to neither.
     */
    private static function zonesHold(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
            $problem = DeliveryZoneRules::problem(is_array($value) ? $value : [], config('giopages.districts'));

            if ($problem !== null) {
                $fail($problem);
            }
        };
    }
}
