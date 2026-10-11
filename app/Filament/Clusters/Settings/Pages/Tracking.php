<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\TrackingProvider;
use App\Filament\Clusters\Settings\Widgets\DeliveryHealthStats;
use App\Filament\Clusters\Settings\Widgets\RecentDeliveryFailures;
use App\Support\Settings\TrackingSettings;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;

/**
 * The store-wide Tracking Config: the one account each Provider reports to,
 * what it reports there, whether it names the Products bought, and whether
 * the Merchant counts their own Visits while it does — with whether any of it
 * is actually leaving the building drawn above and below the form.
 *
 * One section per Provider, the way the Couriers page has one per Courier
 * (ADR-0032): its switch, what it needs to be spoken to, why it cannot be
 * reported to where it cannot, and exactly the events that Provider declares
 * — so the page grows on its own as a Provider gains an event. The access
 * token is a plain `TextInput` here; the page base makes it write-only.
 *
 * Nothing is required. A Provider switched on with a blank credential still
 * records what happens and the sweep holds it until the credential arrives,
 * so a Merchant may paste the dataset today and the token tomorrow. Every
 * event is on until switched off.
 *
 * The two diagnostics widgets are not discovered ones: anything under
 * `app/Filament/Widgets` is drawn on the Dashboard, and "is my tracking
 * working" is an operator's question asked beside the account it is about.
 */
class Tracking extends SettingsPage
{
    protected static string $group = TrackingSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    /** After Payment Methods, which sorts 5: how the store is paid, then what it reports. */
    protected static ?int $navigationSort = 6;

    /**
     * Every declared event drawn at what it actually does, over whatever the
     * row happens to name.
     *
     * An event the row has never mentioned is on — that is the reading every
     * Moment takes, and a switch drawn off would say the opposite of what the
     * installation does. Filled here rather than defaulted on the field
     * because a Toggle reads an absent value as off, and absent is the common
     * case: a fresh installation has decided nothing, and a row written
     * before a Provider gained an event mentions that event nowhere.
     */
    public function mount(): void
    {
        parent::mount();

        $settings = app(TrackingSettings::class);

        foreach (TrackingProvider::cases() as $provider) {
            foreach ($provider->events() as $event) {
                data_set($this->data, "eventDefaults.{$provider->value}.{$event}", $settings->reportsByDefault($provider, $event));
            }
        }
    }

    /**
     * Whether reporting is healthy, above the form that configures it.
     *
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            DeliveryHealthStats::class,
        ];
    }

    /**
     * What never arrived, at the foot of the page, in each Provider's own
     * words.
     *
     * @return array<class-string>
     */
    protected function getFooterWidgets(): array
    {
        return [
            RecentDeliveryFailures::class,
        ];
    }

    /**
     * @return array<Component>
     */
    protected function formComponents(): array
    {
        return [
            ...array_map(self::provider(...), TrackingProvider::cases()),

            Section::make('Products')
                ->description('Whether an event names the teas bought, or only says what the order was worth.')
                ->columns(1)
                ->schema([
                    Toggle::make('namesProducts')
                        ->label('Name the Products bought')
                        ->helperText('On, an ad platform is told which Products were checked out and bought, and learns what sells. Off, it is told the value and the number of items and nothing more. Events already waiting are sent as they were recorded.'),
                ]),

            Section::make('Visits')
                ->description('Your own figure, owing nothing to any ad platform: the Visits tile on the Dashboard.')
                ->columns(1)
                ->schema([
                    Toggle::make('countsVisits')
                        ->label('Count Visits')
                        ->helperText('Off. Counting a Visit is a small database write on every storefront load, so it is not switched on for you. On, every load is counted — every load, not every visitor, which is how an ad platform counts the same thing. Loads of the Order Complete and Retry Pages are not Visits.'),
                ]),

            Section::make('Beacon')
                ->description('The small same-origin request a storefront page makes after it has painted, which is how the server learns about a load that only the browser saw.')
                ->columns(1)
                ->schema([
                    TextInput::make('beaconRateLimit')
                        ->label('Reports per minute from one address')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100000)
                        ->required()
                        ->helperText('Set well above what one visitor produces: mobile carriers put thousands of real visitors behind a single address. Being refused costs the second copy of an event and never the event itself, because the browser\'s own Pixel has already fired.'),
                ]),
        ];
    }

    /**
     * One Provider's account and its events, read from the Provider itself
     * rather than listed here, so nothing on this page has to be edited when
     * one gains an event.
     */
    private static function provider(TrackingProvider $provider): Section
    {
        $label = $provider->getLabel();

        return Section::make($label)
            ->description("The {$label} account the store reports to, and what it reports there.")
            ->columns(1)
            ->schema([
                Toggle::make(TrackingSettings::property($provider, 'Enabled'))
                    ->label("Report to {$label}")
                    ->helperText('Off, and nothing new is recorded or reported. What is already waiting stays until it is too old to send.'),

                TextInput::make(TrackingSettings::property($provider, 'Dataset'))
                    ->label($provider->datasetLabel())
                    ->maxLength(255)
                    ->helperText("From your {$label} Events Manager. The Pixel is on your pages as soon as this is filled."),

                TextInput::make(TrackingSettings::property($provider, 'AccessToken'))
                    ->label('Access token')
                    ->maxLength(1000)
                    ->helperText('What the server sends its own copy of each event with. Until it is filled, events are kept and sent once it is.'),

                self::refusal($provider),

                ...array_map(
                    fn (string $event): Toggle => Toggle::make("eventDefaults.{$provider->value}.{$event}")
                        ->label($event)
                        ->default(true)
                        ->helperText('Off, and the store does not report '.$event.'.'),
                    $provider->events(),
                ),
            ]);
    }

    /**
     * Why the store cannot report to this Provider, in the Provider's own one
     * wording — the words the sweep prints too. Drawn only while it is
     * switched on, because beside an Off switch "switched off" tells nobody
     * anything, and read inside closures so it follows what was last saved
     * rather than what the schema was built with.
     */
    private static function refusal(TrackingProvider $provider): Text
    {
        return Text::make(fn (): string => (string) $provider->driver()->refusal())
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('danger')
            ->visible(fn (): bool => app(TrackingSettings::class)->isEnabled($provider) && $provider->driver()->refusal() !== null);
    }
}
