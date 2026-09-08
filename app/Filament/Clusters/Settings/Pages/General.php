<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Support\Settings\GeneralSettings;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * The values the app read from environment variables before Settings
 * existed: currency, phone rules, the default Delivery Charge and the
 * checkout limits. The fields validate the way the schemas that consume
 * these values already do — money is numeric and never negative, a
 * quantity is a whole number with a floor — so nothing saved here is a
 * value checkout would refuse.
 */
class General extends SettingsPage
{
    protected static string $group = GeneralSettings::class;

    protected static ?int $navigationSort = 0;

    /**
     * @return array<Section>
     */
    protected function formComponents(): array
    {
        return [
            Section::make('Currency')
                ->description('One currency for the whole installation. Prices are stored as minor units; this decides how they are shown.')
                ->columns(1)
                ->schema([
                    TextInput::make('currencyCode')
                        ->label('Code')
                        ->required()
                        ->alpha()
                        ->length(3)
                        ->helperText('ISO code, like BDT or GBP.'),

                    TextInput::make('currencySymbol')
                        ->label('Symbol')
                        ->required()
                        ->maxLength(8)
                        ->helperText('Drawn beside every figure.'),

                    TextInput::make('currencyDecimals')
                        ->label('Decimals')
                        ->required()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(4)
                        ->helperText('Digits after the point. Zero for JPY, three for KWD.'),
                ]),

            Section::make('Phone numbers')
                ->description('A customer is their phone number, so every number is normalised by these rules before it is stored.')
                ->columns(1)
                ->schema([
                    TextInput::make('phoneCountryCode')
                        ->label('Country calling code')
                        ->required()
                        ->regex('/^\d{1,4}$/')
                        ->validationMessages(['regex' => 'Digits only, without the plus sign.'])
                        ->helperText('Bangladesh is 880.'),

                    /**
                     * The one field here that may be blank: some countries
                     * dial no trunk prefix. Filament hands a blank field
                     * over as null, and `PhoneNumber` reads "no prefix" as
                     * the empty string, so blank is put back before it
                     * reaches the group's typed property.
                     */
                    TextInput::make('phoneTrunkPrefix')
                        ->label('Trunk prefix')
                        ->regex('/^\d{1,2}$/')
                        ->dehydrateStateUsing(fn (?string $state): string => (string) $state)
                        ->validationMessages(['regex' => 'Digits only. Leave blank where there is none.'])
                        ->helperText('The leading digit dropped when dialling internationally.'),

                    TextInput::make('phoneNationalLengthMin')
                        ->label('Shortest national number')
                        ->required()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(15)
                        ->helperText('Digits, once the country code and trunk prefix are stripped.'),

                    TextInput::make('phoneNationalLengthMax')
                        ->label('Longest national number')
                        ->required()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(15)
                        ->gte('phoneNationalLengthMin')
                        ->helperText('Loose enough not to refuse a landline a courier could still call.'),
                ]),

            Section::make('Delivery')
                ->description('What a new page starts from. Pages already made keep the delivery options they have.')
                ->columns(1)
                ->schema([
                    TextInput::make('deliveryCharge')
                        ->label('Default delivery charge')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->prefix(fn (): string => (string) ($this->data['currencySymbol'] ?? app(GeneralSettings::class)->currencySymbol))
                        ->helperText('The one delivery option a new page is born with.'),
                ]),

            Section::make('Checkout limits')
                ->description('Abuse protection, tuned to your traffic.')
                ->columns(1)
                ->schema([
                    TextInput::make('checkoutMaxQuantity')
                        ->label('Maximum quantity per line')
                        ->required()
                        ->integer()
                        ->minValue(1)
                        ->helperText('Not a stock check; a cash-on-delivery order for thousands of units is a mistake or an abuse.'),

                    TextInput::make('checkoutRateLimit')
                        ->label('Orders per minute from one address')
                        ->required()
                        ->integer()
                        ->minValue(1)
                        ->helperText('Mobile carriers put thousands of real buyers behind one address, so keep this generous.'),
                ]),
        ];
    }
}
