<?php

namespace App\Filament\Resources\Destinations\Schemas;

use App\Enums\TrackingProvider;
use App\Models\Destination;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class DestinationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                /**
                 * One section, so it takes the full width and hands the two
                 * columns on to its fields; a lone section left at the
                 * resource grid's default span would sit in the left half
                 * with the right half permanently blank.
                 */
                Section::make('Destination')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('label')
                            ->helperText('Your own name for it, so you can tell it apart when a Page picks one.')
                            ->required()
                            ->maxLength(255),

                        Select::make('provider')
                            ->options(TrackingProvider::class)
                            ->default(TrackingProvider::Facebook)
                            ->selectablePlaceholder(false)
                            ->required()

                            /** The credential fields below are labelled in the chosen Provider's own words. */
                            ->live(),

                        TextInput::make('dataset')
                            ->label(fn (Get $get): string => self::provider($get)->datasetLabel())
                            ->helperText(fn (Get $get): string => 'From your '.self::provider($get)->getLabel().' Events Manager.')
                            ->required()
                            ->maxLength(255),

                        /**
                         * Write-only, the way a Settings secret is: it loads
                         * blank whatever is stored, says so with a hint, and
                         * a blank save keeps the stored value rather than
                         * destroying it. `dehydrated()` is what does the
                         * last part — a blank field is left out of the saved
                         * data entirely, so the column is not written.
                         */
                        TextInput::make('access_token')
                            ->label('Access token')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (?Destination $record): bool => $record === null)
                            ->hint(fn (?Destination $record): ?string => $record?->hasAccessToken()
                                ? 'A value is stored. Leave blank to keep it.'
                                : null)
                            ->helperText('Stored encrypted and never shown back to you.'),

                        Toggle::make('enabled')
                            ->helperText('Switch off to pause reporting without deleting this Destination.')
                            ->default(true),

                        Toggle::make('is_default')
                            ->label('Default for this provider')
                            ->helperText('Where a Page that has not picked a Destination reports. Marking this unmarks the current default.')
                            ->default(false),
                    ]),
            ]);
    }

    /**
     * The Provider the form currently has chosen, falling back to the first
     * so a label closure never runs against a blank on the create screen.
     */
    private static function provider(Get $get): TrackingProvider
    {
        $chosen = $get('provider');

        if ($chosen instanceof TrackingProvider) {
            return $chosen;
        }

        return (is_string($chosen) ? TrackingProvider::tryFrom($chosen) : null) ?? TrackingProvider::Facebook;
    }
}
