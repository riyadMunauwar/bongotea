<?php

namespace App\Filament\Concerns;

use App\Enums\ImageSlot;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;

/**
 * What a record says about itself to search engines and to whatever unfurls a
 * shared link: one section, drawn the same way on every form that has an
 * address of its own.
 *
 * Each field is optional; a blank one falls back to what the record already
 * says (its name, its description, its cover) when the storefront draws it.
 *
 * Collapsed, because it is the last thing anybody fills in. Stacked in a form's
 * sidebar; across the page, the words sit beside the share image.
 */
trait HasSeoFields
{
    /** The public disk a share image is stored on (ADR-0011); the row keeps only the path. */
    public const SEO_IMAGE_DISK = 'local';

    public const SEO_IMAGE_DIRECTORY = 'seo';

    protected static function seoSection(bool $acrossThePage = false): Section
    {
        return Section::make('Search and sharing')
            ->description('Leave blank to use the name, description and first image.')
            ->collapsible()
            ->collapsed()
            ->columnSpanFull()
            ->columns(['default' => 1, 'lg' => $acrossThePage ? 2 : 1])
            ->schema([
                Group::make([
                    TextInput::make('seo_title')
                        ->label('SEO title')
                        ->maxLength(255),

                    Textarea::make('seo_description')
                        ->label('SEO description')
                        ->rows(3)
                        ->maxLength(500),
                ]),

                ImageSlot::ShareImage->fit(FileUpload::make('seo_image'))
                    ->label('Share image')
                    ->disk(self::SEO_IMAGE_DISK)
                    ->directory(self::SEO_IMAGE_DIRECTORY)
                    ->visibility('public')
                    ->image()
                    ->maxSize(2048),
            ]);
    }
}
