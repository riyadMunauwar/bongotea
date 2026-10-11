<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\Courier;
use App\Support\Couriers\ParcelField;
use App\Support\Settings\CourierSettings;
use BackedEnum;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;

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
 *
 * The pickup stores are the one list here rather than a value: a Merchant with
 * two warehouses registers both, names one the default, and is asked which one
 * a parcel leaves from when they send it. A Merchant with one registers one and
 * is never asked anything, which is the case this page is laid out for.
 */
class Couriers extends SettingsPage
{
    protected static string $group = CourierSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    /** After Tracking, which sorts 4: the last of the things decided once. */
    protected static ?int $navigationSort = 5;

    /**
     * The stores are a Repeater over a list the group normalises, so they are
     * read back through the group rather than off the properties — which is
     * what puts the single store an installation had before this list existed
     * into the Repeater as a row, named after its own id, with nobody
     * retyping anything. Saving it is what makes it a registered store.
     *
     * @return array<string, mixed>
     */
    protected function formValues(): array
    {
        $settings = app(CourierSettings::class);
        $registered = [];

        foreach (Courier::cases() as $courier) {
            if (! property_exists($settings, CourierSettings::property($courier, 'PickupStores'))) {
                continue;
            }

            $rows = [];

            foreach ($settings->pickupStoresFor($courier) as $id => $name) {
                $rows[] = [CourierSettings::STORE_ID => (string) $id, CourierSettings::STORE_NAME => $name];
            }

            $registered[CourierSettings::property($courier, 'PickupStores')] = $rows;
        }

        return [...parent::formValues(), ...$registered];
    }

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

                    ...self::parcelDefaults(Courier::Steadfast),
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

                    ...self::parcelDefaults(
                        Courier::Pathao,
                        'The Pathao stores your parcels are collected from. Pathao will not take a booking without one.',
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

                    ...self::parcelDefaults(
                        Courier::RedEx,
                        'The RedEx pickup stores your parcels are collected from.',
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
     * Every warehouse this Courier collects from, and which of them a parcel
     * goes from when nobody says otherwise.
     *
     * A store is the Courier's own id for it: the same warehouse is a
     * different number at each Courier, so these lists are registered per
     * Courier and never shared. The name beside it is the Merchant's own and
     * is never sent anywhere — it is what makes the choice readable on the
     * send dialog, which is the only place it is ever put in front of anybody,
     * and only once there are two of them.
     *
     * A Merchant with one store is the common case and is never asked, so the
     * list is not required. A Courier with no notion of a store at all is
     * never drawn one (`parcelDefaults()`).
     */
    private static function pickupStores(Courier $courier, string $hint): Fieldset
    {
        $registered = CourierSettings::property($courier, 'PickupStores');

        return Fieldset::make('Pickup stores')
            ->columns(1)
            ->schema([
                Repeater::make($registered)
                    ->label('Registered stores')
                    ->helperText($hint)
                    ->addActionLabel('Add a pickup store')
                    ->defaultItems(0)
                    ->columns(2)
                    ->itemLabel(fn (array $state): ?string => self::storeLabel($state))

                    /**
                     * The default follows the list. A Merchant who deletes the
                     * warehouse it named, or corrects that store's id, has not
                     * said their parcels now leave from nowhere — and watching
                     * the default move is how they find out where they do
                     * leave from. Settled here and not only in the group: the
                     * Select refuses a value it does not offer, and a form
                     * that is refused settles nothing at all.
                     */
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::settleDefaultStore($courier, $get, $set))

                    /**
                     * On the repeater rather than on the row, because it is
                     * the only place that can see every row at once. The same
                     * id registered twice is one store the Merchant is offered
                     * two of, and which of the two they pick decides nothing.
                     */
                    ->rules([
                        fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                            $ids = array_map(
                                fn (mixed $store): string => is_array($store) ? trim((string) ($store[CourierSettings::STORE_ID] ?? '')) : '',
                                array_values((array) $value),
                            );

                            /** A blank one is left to the row's own `required`, and "0" is an id a Courier may perfectly well have issued. */
                            $ids = array_filter($ids, fn (string $id): bool => $id !== '');

                            if (count($ids) !== count(array_unique($ids))) {
                                $fail('Two pickup stores cannot share an id. The id is what the courier is told, so the same one twice is one store.');
                            }
                        },
                    ])
                    ->schema([
                        TextInput::make(CourierSettings::STORE_ID)
                            ->label('Store id')
                            ->required()
                            ->maxLength(255)

                            /** So the default below offers a store the moment it has an id to offer it under. */
                            ->live(onBlur: true)
                            ->helperText($courier->getLabel().'\'s own id for this store, from your '.$courier->getLabel().' dashboard. It is what they are told.'),

                        TextInput::make(CourierSettings::STORE_NAME)
                            ->label('Name')
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->helperText('What you call this warehouse — "Mirpur", "the shop". Yours alone; '.$courier->getLabel().' is never told it.'),
                    ]),

                Select::make(CourierSettings::property($courier, 'PickupStore'))
                    ->label('Default pickup store')
                    ->options(fn (Get $get): array => CourierSettings::storeOptions($get($registered)))
                    ->native(false)
                    ->helperText('Where a parcel goes from when nobody chooses. With one store registered nobody is ever asked; with two, the send dialog opens on this one and a change there applies to that parcel alone.'),
            ]);
    }

    /**
     * Point the default at a store the Merchant still has, the moment the list
     * stops holding the one it named — and at the first of them where they
     * have registered stores and named none, so the screen says which one a
     * parcel will leave from rather than settling it quietly on save.
     *
     * The same rule `CourierSettings::with()` applies to a row, said again to
     * the form. The group's is what guards a row that never came through here;
     * this one is what keeps the Merchant from being refused over a field they
     * did not touch.
     */
    private static function settleDefaultStore(Courier $courier, Get $get, Set $set): void
    {
        $default = CourierSettings::property($courier, 'PickupStore');
        $options = CourierSettings::storeOptions($get(CourierSettings::property($courier, 'PickupStores')));

        if ($options === []) {
            /** Nothing registered is nothing to default to, and a default naming a store nobody has is worse than none. */
            $set($default, null);

            return;
        }

        if (! array_key_exists((string) $get($default), $options)) {
            $set($default, (string) array_key_first($options));
        }
    }

    /**
     * A registered store as the list reads it: the Merchant's own name for it,
     * or the id while it has no name.
     *
     * @param  array<string, mixed>  $state
     */
    private static function storeLabel(array $state): ?string
    {
        foreach ([CourierSettings::STORE_NAME, CourierSettings::STORE_ID] as $key) {
            if (filled($state[$key] ?? null)) {
                return (string) $state[$key];
            }
        }

        return null;
    }

    /**
     * What an ordinary parcel looks like to one Courier: what every booking
     * starts from, so a whole morning's orders can go out without a decision
     * being made about any of them.
     *
     * Only what the Courier's driver sends (`parcelFields()`) is offered — a
     * default nothing sends is a question whose answer goes nowhere — and the
     * pickup stores come with the pickup-store field, so a Courier that is
     * never told a store is never shown a list of them.
     *
     * The weight and the declared value are not decoration. RedEx refuses a
     * parcel without both, and nothing on a page asks a customer how heavy
     * their order is — these are the only answer there is.
     *
     * @return list<Fieldset>
     */
    private static function parcelDefaults(Courier $courier, string $pickupStoresHint = ''): array
    {
        $sent = array_map(
            fn (ParcelField $field): string => $field->settingsProperty,
            $courier->driver()->parcelFields(),
        );

        $inputs = array_filter([
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
        ], fn (TextInput $input): bool => in_array($input->getName(), $sent, true));

        return array_values(array_filter([
            in_array(CourierSettings::property($courier, 'PickupStore'), $sent, true)
                ? self::pickupStores($courier, $pickupStoresHint)
                : null,

            $inputs === []
                ? null
                : Fieldset::make('Default parcel')->columns(2)->schema(array_values($inputs)),
        ]));
    }
}
