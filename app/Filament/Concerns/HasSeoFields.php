<?php

namespace App\Filament\Concerns;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;

/**
 * What a record says about itself to search engines and to whatever unfurls a
 * shared link: one section, drawn the same way on every form that has an
 * address of its own.
 *
 * Each field is optional; a blank one falls back to what the record already
 * says (its name, its description, its cover) when the storefront draws it.
 */
trait HasSeoFields
{
    /** The public disk a share image is stored on (ADR-0011); the row keeps only the path. */
    public const SEO_IMAGE_DISK = 'local';

    public const SEO_IMAGE_DIRECTORY = 'seo';

    protected static function seoSection(): Section
    {
        return Section::make('Search and sharing')
            ->description('Leave blank to use the name, description and first image.')
            ->collapsible()
            ->collapsed()
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                TextInput::make('seo_title')
                    ->label('SEO title')
                    ->maxLength(255),

                FileUpload::make('seo_image')
                    ->label('Share image')
                    ->disk(self::SEO_IMAGE_DISK)
                    ->directory(self::SEO_IMAGE_DIRECTORY)
                    ->visibility('public')
                    ->image()
                    ->maxSize(2048)
                    ->imagePreviewHeight('80'),

                Textarea::make('seo_description')
                    ->label('SEO description')
                    ->rows(2)
                    ->maxLength(500)
                    ->columnSpanFull(),
            ]);
    }
}
