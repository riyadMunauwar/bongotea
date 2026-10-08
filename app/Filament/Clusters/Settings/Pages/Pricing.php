<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Support\Money;
use App\Support\Pricing\KilogramRanges;
use App\Support\Settings\GeneralSettings;
use App\Support\Settings\PricingSettings;
use BackedEnum;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

/**
 * The Tiers and the Delivery Bands (ADR-0028).
 *
 * Each table is a list of kilogram ranges that must cover every whole
 * kilogram from one upwards without gap or overlap, the last open-ended; the
 * page refuses to save anything else, so every Cart weight has exactly one
 * price and one Delivery Charge. A Product's prices per Tier are set on the
 * Product; only the ranges are set here.
 */
class Pricing extends SettingsPage
{
    protected static string $group = PricingSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyBangladeshi;

    /** After Menus: the last of the storefront's groups. */
    protected static ?int $navigationSort = 4;

    /**
     * @return array<Section>
     */
    protected function formComponents(): array
    {
        return [
            Section::make('Tiers')
                ->description('The cart\'s total kilograms, across every tea in it, decide which Tier every line is priced from. Each Product sets its own discount per Tier.')
                ->schema([
                    Repeater::make('tiers')
                        ->hiddenLabel()
                        ->addActionLabel('Add a Tier')
                        ->defaultItems(0)
                        ->columns(2)
                        ->rules([self::coversEveryKilogram('Tier')])
                        ->schema([
                            /**
                             * What a Product's Tier Discounts are stored
                             * against: a new Tier starts with none, so it
                             * charges the Tier below's price until filled in.
                             */
                            Hidden::make('key')
                                ->default(fn (): string => self::newTierKey())
                                ->dehydrateStateUsing(fn (mixed $state): string => filled($state) ? (string) $state : self::newTierKey()),

                            ...self::rangeFields(),
                        ]),
                ]),

            Section::make('Delivery Bands')
                ->description('The Delivery Charge, by the same total kilograms, the same across the country. Where a band ends says nothing about where a price changes.')
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
        ];
    }

    private static function newTierKey(): string
    {
        return Str::lower((string) Str::ulid());
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
