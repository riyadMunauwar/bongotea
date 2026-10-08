<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\TrackingProvider;
use App\Models\Destination;
use App\Support\Settings\TrackingSettings;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The store-wide Tracking Config: where the store reports, what it reports
 * there, whether it names the Products bought, and whether the Merchant
 * counts their own Visits while it does.
 *
 * One section per Provider, with its default Destination and exactly the
 * events that Provider declares — so the page grows on its own as a Provider
 * gains an event, and a second platform is a section nobody had to write.
 *
 * The default Destination is the Destination's own flag rather than a
 * property of the group: `DestinationObserver` keeps exactly one per
 * Provider, and a second copy in the settings row would be a second thing to
 * keep true when one is deleted.
 *
 * Everything is on by default and nothing is required. A Merchant who never
 * opens this page reports everything their Providers produce.
 */
class Tracking extends SettingsPage
{
    protected static string $group = TrackingSettings::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    /** After the Destinations resource, which sorts 6: where events go, then which of them do. */
    protected static ?int $navigationSort = 7;

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
     * The group's properties, and each Provider's default Destination as the
     * Destinations have it.
     *
     * @return array<string, mixed>
     */
    protected function formValues(): array
    {
        return [
            ...parent::formValues(),
            'defaultDestinations' => Destination::query()
                ->where('is_default', true)
                ->get()
                ->mapWithKeys(fn (Destination $destination): array => [$destination->provider->value => $destination->getKey()])
                ->all(),
        ];
    }

    /**
     * The group, and each chosen default Destination, saved together or not
     * at all.
     */
    public function save(): void
    {
        $chosen = $this->form->getState()['defaultDestinations'] ?? [];

        DB::transaction(function () use ($chosen): void {
            parent::save();

            $this->markDefaultDestinations($chosen);
        });
    }

    /**
     * The group's values: the default Destinations are not among them.
     *
     * @return array<string, mixed>
     */
    protected function valuesToSave(): array
    {
        return Arr::except(parent::valuesToSave(), 'defaultDestinations');
    }

    /**
     * Mark each chosen Destination default — the observer turns that into
     * "and no other" — and log the ones that changed, as a saved setting is.
     *
     * @param  array<string, int|string|null>  $chosen  Destination ids keyed by Provider
     */
    private function markDefaultDestinations(array $chosen): void
    {
        $destinations = Destination::query()
            ->whereKey(array_filter($chosen))
            ->where('is_default', false)
            ->get()
            ->filter(fn (Destination $destination): bool => (string) ($chosen[$destination->provider->value] ?? '') === (string) $destination->getKey());

        foreach ($destinations as $destination) {
            $destination->update(['is_default' => true]);

            activity()
                ->causedBy(auth()->user())
                ->performedOn($destination)
                ->event('updated')
                ->log("Made {$destination->label} the default {$destination->provider->getLabel()} Destination");
        }
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
     * One Provider's default Destination and its events, read from the
     * Provider itself rather than listed here, so nothing on this page has to
     * be edited when one gains an event.
     */
    private static function provider(TrackingProvider $provider): Section
    {
        return Section::make($provider->getLabel())
            ->description('Where the store reports to '.$provider->getLabel().', and what it reports there.')
            ->columns(1)
            ->schema([
                self::defaultDestination($provider),

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
     * Which of the Provider's Destinations the store reports to. Never
     * clearable: a Provider with Destinations always has a default, and
     * reporting nowhere is switching the Destination off.
     */
    private static function defaultDestination(TrackingProvider $provider): Select
    {
        $destinations = fn (): array => Destination::query()
            ->where('provider', $provider)
            ->orderBy('label')
            ->pluck('label', 'id')
            ->all();

        return Select::make("defaultDestinations.{$provider->value}")
            ->label('Default Destination')
            ->options($destinations)
            ->selectablePlaceholder(false)
            ->placeholder('No '.$provider->getLabel().' Destination yet')
            ->disabled(fn (Select $component): bool => $component->getOptions() === [])
            ->helperText('Where the store reports. A default that is switched off reports nowhere. Destinations are added and switched off under Destinations.');
    }
}
