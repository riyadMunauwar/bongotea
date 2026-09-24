<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Support\Settings\GeneralSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;

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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 0;

    /**
     * @return array<Section>
     */
    protected function formComponents(): array
    {
        return [
            Section::make('Currency')
                ->description('One currency for the whole installation. Prices are stored as minor units; this decides how they are shown.')
                ->columns(3)
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

                    /**
                     * Every stored price and total is an integer in minor
                     * units whose scale is this value, so changing it once
                     * money exists would rescale all of it in place. The
                     * field locks at the first Product or Order, trashed
                     * ones included; a disabled field is left out of the
                     * saved state, so the stored value survives a save.
                     */
                    TextInput::make('currencyDecimals')
                        ->label('Decimals')
                        ->required()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(4)
                        ->disabled(fn (): bool => self::moneyHasBeenStored())
                        ->helperText(fn (): string => self::moneyHasBeenStored()
                            ? 'Fixed: prices and orders are already stored at this scale.'
                            : 'Digits after the point. Zero for JPY, three for KWD. Fixed once a product or order exists.'),
                ]),

            Section::make('Timezone')
                ->description('Where your day begins and ends. Order times are shown in it and reports cut their days at its midnight; nothing stored changes.')
                ->columns(3)
                ->schema([
                    /**
                     * The options list is the validation: Filament refuses a
                     * value outside it, so a name PHP does not know never
                     * reaches the row. The group guards the row itself for
                     * the case where one got there by hand.
                     */
                    Select::make('timezone')
                        ->label('Timezone')
                        ->required()
                        ->searchable()
                        ->options(function (): array {
                            $zones = timezone_identifiers_list();

                            return array_combine($zones, $zones);
                        })
                        ->helperText('Bangladesh is Asia/Dhaka.'),
                ]),

            Section::make('Front page')
                ->description('Which page answers your bare domain. It keeps its own address as well, so nothing you have advertised stops working.')
                ->columns(3)
                ->schema([
                    /**
                     * Every Page that is not in the bin, drafts included.
                     *
                     * Drafts are offered for two reasons. A Merchant can line
                     * one up before launch — the Site Root shows the
                     * Placeholder until it is published — and, more
                     * importantly, unpublishing the Front Page then never
                     * removes it from the option set. A `Select` derives an
                     * implicit `in:` rule from its options, so an option that
                     * vanishes makes *this whole page* unsaveable: currency,
                     * timezone and phone rules all refused because of
                     * something the Merchant did on another screen entirely.
                     *
                     * The stored value is forced back in whatever state it is
                     * in, which closes the same trap for a Page that was
                     * binned or deleted outright. The group's own guard cannot
                     * stand in for this: validation runs before `with()` does.
                     */
                    Select::make('frontPageId')
                        ->label('Front page')
                        ->searchable()
                        ->placeholder('The GioPages placeholder')
                        ->options(fn (): array => self::pageOptions())
                        ->helperText('Leave this empty and your domain shows a GioPages placeholder instead.'),
                ]),

            Section::make('Phone numbers')
                ->description('A customer is their phone number, so every number is normalised by these rules before it is stored.')
                ->columns(2)
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
                     * over as null, and the group reads that as the empty
                     * string `PhoneNumber` takes for "no prefix".
                     */
                    TextInput::make('phoneTrunkPrefix')
                        ->label('Trunk prefix')
                        ->regex('/^\d{1,2}$/')
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
                ->columns(2)
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
                ->columns(2)
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

    /**
     * "No Front Page" is zero to the group and blank to the form.
     *
     * A `Select` refuses a state that is not one of its options, and zero never
     * is — so a fresh installation, whose group holds the config default of
     * zero, would open on a field that refuses to save and take currency,
     * timezone and phone rules down with it. Blank is what the Select's own
     * placeholder means, and `coerce()` reads the null that comes back as zero
     * again on the way into the row.
     *
     * Done here rather than after `mount()` because this is when a component
     * gets to reshape its own state; a value written afterwards has missed it.
     *
     * @return array<string, mixed>
     */
    protected function formValues(): array
    {
        $values = parent::formValues();

        $values['frontPageId'] = ($values['frontPageId'] ?? 0) > 0 ? $values['frontPageId'] : null;

        return $values;
    }

    /**
     * The Pages a Merchant may designate, keyed by id.
     *
     * An id and not a Slug: a Slug can be changed, and a rename would silently
     * orphan the pointer and drop the Site Root back to the Placeholder with
     * nothing to explain why.
     *
     * A Page whose designation no longer qualifies is added back at the end and
     * labelled, so the field can always render and save what is stored. Without
     * it, binning the Front Page would raise a validation error on a field
     * nobody touched and lock the rest of this page with it.
     *
     * @return array<int, string>
     */
    private static function pageOptions(): array
    {
        $options = Page::query()
            ->orderBy('title')
            ->get(['id', 'title', 'slug', 'status'])
            ->mapWithKeys(fn (Page $page): array => [
                $page->getKey() => self::pageLabel($page),
            ])
            ->all();

        $stored = app(GeneralSettings::class)->frontPageId;

        if ($stored > 0 && ! array_key_exists($stored, $options)) {
            $gone = Page::withTrashed()->find($stored);

            $options[$stored] = $gone === null
                ? 'A page that has been deleted'
                : self::pageLabel($gone);
        }

        return $options;
    }

    /**
     * How a Page reads in the dropdown: its title, the address it answers at,
     * and why it is not serving where that is the case.
     */
    private static function pageLabel(Page $page): string
    {
        $state = match (true) {
            $page->trashed() => ' (in the bin)',
            ! $page->isPublished() => ' (draft)',
            default => '',
        };

        return $page->title.' — /'.$page->slug.$state;
    }

    /**
     * Whether any figure has been stored at the current decimal scale.
     * Trashed rows count: a restored Product or Order is stored money too.
     */
    private static function moneyHasBeenStored(): bool
    {
        return Product::withTrashed()->exists() || Order::withTrashed()->exists();
    }
}
