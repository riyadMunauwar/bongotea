<?php

namespace Tests\Fixtures\Settings;

use App\Filament\Clusters\Settings\Pages\SettingsPage;
use Filament\Forms\Components\Textarea;

/**
 * A page that declares its secret as the wrong kind of field, to show the
 * base page refuses it rather than rendering the secret in a plain box.
 */
class SecretTextareaPage extends SettingsPage
{
    protected static string $group = SecretSettings::class;

    protected static ?string $slug = 'test-secret-textarea';

    /**
     * @return array<Textarea>
     */
    protected function formComponents(): array
    {
        return [
            Textarea::make('apiToken')->label('API token'),
        ];
    }
}
