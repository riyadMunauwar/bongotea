<?php

namespace App\Filament\Resources\Pages\Schemas\Tracking;

use App\Enums\TrackingChoice;
use App\Enums\TrackingProvider;
use App\Support\Settings\TrackingSettings;
use App\Support\Tracking\TrackingConfig;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Which of each Provider's events this Page fires.
 *
 * Three states and not two, because the useful answer for nearly every event
 * on nearly every Page is "whatever the installation decided" — a Merchant who
 * turns `ViewContent` off in Settings has turned it off here too, and a Page
 * that wants it anyway says so. Stored as a nullable boolean where null is
 * "nothing said", which is what lets a Provider gain a fifth event with no
 * data migration and no edit to a single Page.
 *
 * The list is read from the Provider itself, so this section grows on its own
 * as a Provider gains events and a second platform is a section nobody had to
 * write.
 */
class Events
{
    public static function section(): Section
    {
        return Section::make('Events')
            ->description('What this Page reports, per Provider. Use default is whatever the Tracking settings say, so a decision made once there holds here until this Page says otherwise.')
            ->columns(1)
            ->schema(array_map(self::provider(...), TrackingProvider::cases()));
    }

    /**
     * The Config this tab currently describes, read through the same total
     * reading a stored Config goes through.
     *
     * The seam every other section on the tab reads the switches through: the
     * sections below say what this Page will send, and they answer from the
     * Config the save is about to write rather than from the form's own
     * vocabulary, so the tab and the Page cannot come to mean different
     * things.
     */
    public static function asConfig(Get $get): TrackingConfig
    {
        $state = (array) $get('tracking_config');

        return TrackingConfig::fromArray([
            ...$state,
            'events' => self::stored($state['events'] ?? null),
        ]);
    }

    /**
     * One Provider's switches, in the order its Moments produce them.
     */
    private static function provider(TrackingProvider $provider): Section
    {
        return Section::make($provider->getLabel())
            ->columns(1)
            ->schema(array_map(
                fn (string $event): ToggleButtons => self::choice($provider, $event),
                $provider->events(),
            ));
    }

    private static function choice(TrackingProvider $provider, string $event): ToggleButtons
    {
        return ToggleButtons::make("tracking_config.events.{$provider->value}.{$event}")
            ->label($event)
            ->inline()
            ->options(TrackingChoice::labels())
            ->default(TrackingChoice::Inherit->value)
            /**
             * The form speaks in three words and the Config stores a nullable
             * boolean; the enum is the only place that maps between them, so
             * a switch read on the tab and a switch read on the path of a
             * Moment cannot disagree.
             */
            ->formatStateUsing(fn (mixed $state): string => TrackingChoice::fromStored($state)->value)
            ->dehydrateStateUsing(fn (mixed $state): ?bool => TrackingChoice::fromStored($state)->stored())
            /** So the preview below follows the switches as they are set, rather than at the next save. */
            ->live()
            ->helperText(fn (): string => 'The default is '.(app(TrackingSettings::class)->reportsByDefault($provider, $event) ? 'on' : 'off').'.');
    }

    /**
     * The tab's switches as a Config stores them: the three words turned back
     * into yes, no and nothing said.
     *
     * @return array<string, array<string, ?bool>>
     */
    private static function stored(mixed $state): array
    {
        if (! is_array($state)) {
            return [];
        }

        $stored = [];

        foreach ($state as $provider => $events) {
            if (! is_string($provider) || ! is_array($events)) {
                continue;
            }

            foreach ($events as $event => $choice) {
                if (is_string($event)) {
                    $stored[$provider][$event] = TrackingChoice::fromStored($choice)->stored();
                }
            }
        }

        return $stored;
    }
}
