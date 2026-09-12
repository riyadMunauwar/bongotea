<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\Courier;
use App\Support\Settings\CourierSettings;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;

/**
 * Where a Merchant switches each Courier on for the installation, types what
 * it needs to be spoken to, and says what an ordinary parcel of theirs looks
 * like.
 *
 * One section per Courier, in the enum's order. The credentials are plain
 * `TextInput`s here: the page base reads the group's secrets list and makes
 * every one of them write-only, sealed at rest and rotated-only in the log
 * (ADR-0012). Nothing on this page says "secret".
 *
 * A switched-on Courier with a blank credential is offered nowhere — the
 * group's rule, not this page's — so nothing here is required: a Merchant may
 * switch a Courier on and finish typing later, and whatever would have
 * offered it words why it cannot be.
 */
class Couriers extends SettingsPage
{
    protected static string $group = CourierSettings::class;

    /** After Tracking, which sorts 3: the last of the things decided once. */
    protected static ?int $navigationSort = 4;

    /**
     * @return array<Component>
     */
    protected function formComponents(): array
    {
        return [
            Section::make(Courier::Steadfast->getLabel())
                ->description('Parcels booked on your Steadfast account, using the API key and secret key from your Steadfast dashboard.')
                ->columns(1)
                ->schema([
                    self::switch(Courier::Steadfast),

                    TextInput::make('steadfastApiKey')
                        ->label('API key')
                        ->maxLength(255)
                        ->helperText('Issued by Steadfast with your account, beside the secret key.'),

                    TextInput::make('steadfastSecretKey')
                        ->label('Secret key')
                        ->maxLength(255),

                    self::defaults(
                        Courier::Steadfast,
                        'Steadfast collects from one arrangement per account and is never told a store, so leave this blank.',
                    ),
                ]),

            Section::make(Courier::Pathao->getLabel())
                ->description('Parcels booked on your Pathao merchant account. Pathao issues its own access token from these four credentials and refreshes it before it expires, so there is no token to paste here.')
                ->columns(1)
                ->schema([
                    self::switch(Courier::Pathao),

                    TextInput::make('pathaoClientId')
                        ->label('Client id')
                        ->maxLength(255)
                        ->helperText('Issued by Pathao at onboarding, with the client secret.'),

                    TextInput::make('pathaoClientSecret')
                        ->label('Client secret')
                        ->maxLength(255),

                    TextInput::make('pathaoUsername')
                        ->label('Username')
                        ->maxLength(255)
                        ->helperText('Your Pathao merchant account username, used to obtain a token.'),

                    TextInput::make('pathaoPassword')
                        ->label('Password')
                        ->maxLength(255),

                    self::defaults(
                        Courier::Pathao,
                        'The id of the Pathao store a parcel is collected from. Pathao will not take a booking without one.',
                    ),
                ]),

            Section::make(Courier::RedEx->getLabel())
                ->description('Parcels booked on your RedEx account. RedEx will not take a parcel without a weight and a declared value, so the defaults below are what let an order go out without anybody measuring anything.')
                ->columns(1)
                ->schema([
                    self::switch(Courier::RedEx),

                    TextInput::make('redexAccessToken')
                        ->label('Access token')
                        ->maxLength(1024)
                        ->helperText('Issued by RedEx for your account. It is the whole of how they recognise you.'),

                    self::defaults(
                        Courier::RedEx,
                        'The id of the RedEx pickup store a parcel is collected from.',
                    ),
                ]),
        ];
    }

    private static function switch(Courier $courier): Toggle
    {
        return Toggle::make(CourierSettings::property($courier, 'Enabled'))
            ->label('Use '.$courier->getLabel())
            ->helperText('Off, and no order can be sent to them whatever else is filled in.');
    }

    /**
     * What an ordinary parcel looks like to one Courier: what every booking
     * starts from, so a whole morning's orders can go out without a decision
     * being made about any of them.
     *
     * The weight and the declared value are not decoration. RedEx refuses a
     * parcel without both, and nothing on a page asks a customer how heavy
     * their order is — these are the only answer there is.
     */
    private static function defaults(Courier $courier, string $pickupStoreHint): Fieldset
    {
        return Fieldset::make('Default parcel')
            ->columns(2)
            ->schema([
                TextInput::make(CourierSettings::property($courier, 'PickupStore'))
                    ->label('Pickup store')
                    ->maxLength(255)
                    ->helperText($pickupStoreHint),

                TextInput::make(CourierSettings::property($courier, 'DeliveryType'))
                    ->label('Delivery type')
                    ->maxLength(255)
                    ->helperText($courier->getLabel().'\'s own code for the kind of delivery an ordinary order gets.'),

                TextInput::make(CourierSettings::property($courier, 'ItemType'))
                    ->label('Item type')
                    ->maxLength(255)
                    ->helperText($courier->getLabel().'\'s own code for what is inside an ordinary parcel.'),

                TextInput::make(CourierSettings::property($courier, 'ParcelWeight'))
                    ->label('Parcel weight')
                    ->numeric()
                    ->minValue(0)
                    ->suffix('kg')
                    ->helperText('What an ordinary parcel weighs, when nothing has been measured.'),

                TextInput::make(CourierSettings::property($courier, 'DeclaredValue'))
                    ->label('Declared value')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('What an ordinary parcel is declared to be worth, in your currency.'),
            ]);
    }
}
