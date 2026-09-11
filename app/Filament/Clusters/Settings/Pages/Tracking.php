<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Enums\TrackingProvider;
use App\Support\Settings\TrackingSettings;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;

/**
 * What every Page reports unless it says otherwise.
 *
 * One section per Provider, listing exactly the events that Provider declares
 * — so the page grows on its own as a Provider gains an event, and a second
 * platform is a section nobody had to write. A Page's own Tracking tab
 * overrules any of these; silence there inherits what is decided here.
 *
 * Everything is on by default and nothing is required. A Merchant who never
 * opens this page reports everything their Pages' Providers produce, which is
 * what an installation did before this page existed.
 */
class Tracking extends SettingsPage
{
    protected static string $group = TrackingSettings::class;

    /** After the Destinations resource, which sorts 2: where events go, then which of them do. */
    protected static ?int $navigationSort = 3;

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
     * @return array<Component>
     */
    protected function formComponents(): array
    {
        return [
            ...array_map(self::provider(...), TrackingProvider::cases()),

            Section::make('Beacon')
                ->description('The small same-origin request a tracked Page makes after it has painted, which is how the server learns about a load that only the browser saw.')
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
     * One Provider's events, read from the Provider itself rather than listed
     * here, so nothing on this page has to be edited when one gains an event.
     */
    private static function provider(TrackingProvider $provider): Section
    {
        return Section::make($provider->getLabel())
            ->description('What a Page reports to a '.$provider->getLabel().' Destination unless its own Tracking tab says otherwise.')
            ->columns(1)
            ->schema(array_map(
                fn (string $event): Toggle => Toggle::make("eventDefaults.{$provider->value}.{$event}")
                    ->label($event)
                    ->default(true)
                    ->helperText('Off, and no Page reports '.$event.' except one that switches it on for itself.'),
                $provider->events(),
            ));
    }
}
