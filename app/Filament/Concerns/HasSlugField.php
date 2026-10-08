<?php

namespace App\Filament\Concerns;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

/**
 * A name and the Slug it suggests, drawn one way on every catalogue form.
 *
 * The name fills a blank slug as it is typed and never overwrites one, so a
 * slug written by hand — the usual case for a Bangla name, which has no
 * ASCII spelling to suggest — survives a later rename.
 */
trait HasSlugField
{
    protected static function nameInput(): TextInput
    {
        return TextInput::make('name')
            ->required()
            ->maxLength(255)
            ->live(onBlur: true)
            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                if (blank($get('slug')) && filled($state)) {
                    $set('slug', Str::slug($state));
                }
            });
    }

    protected static function slugInput(?string $addressPrefix = null): TextInput
    {
        return TextInput::make('slug')
            ->required()
            ->maxLength(255)
            ->unique(ignoreRecord: true)
            ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
            ->prefix($addressPrefix)
            ->helperText('Lowercase English letters, digits and hyphens.');
    }
}
