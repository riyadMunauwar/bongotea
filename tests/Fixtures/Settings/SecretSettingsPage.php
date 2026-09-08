<?php

namespace Tests\Fixtures\Settings;

use App\Filament\Clusters\Settings\Pages\SettingsPage;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * The plainest page over `SecretSettings`: two ordinary text inputs, one of
 * which is a secret. The page declares nothing about that; the base page is
 * meant to read it off the group.
 */
class SecretSettingsPage extends SettingsPage
{
    protected static string $group = SecretSettings::class;

    protected static ?string $slug = 'test-secret';

    /**
     * @return array<Section>
     */
    protected function formComponents(): array
    {
        return [
            Section::make('Credentials')
                ->columns(1)
                ->schema([
                    TextInput::make('apiToken')->label('API token'),
                    TextInput::make('label')->label('Label')->required(),
                ]),
        ];
    }
}
