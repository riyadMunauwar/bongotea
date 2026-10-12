<?php

namespace App\Filament\Resources\Brands\Schemas;

use App\Enums\ImageSlot;
use App\Filament\Concerns\HasSlugField;
use App\Models\Brand;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Schema;

/**
 * Drawn in a dialog over the Brands list: the name and slug side by side, the
 * square logo under them.
 *
 * There are no Brand pages; the slug is what a Brand filter names in an
 * address.
 */
class BrandForm
{
    use HasSlugField;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                self::nameInput(),

                self::slugInput(),

                ImageSlot::BrandLogo->fit(FileUpload::make('logo'))
                    ->disk(Brand::LOGO_DISK)
                    ->directory(Brand::LOGO_DIRECTORY)
                    ->visibility('public')
                    ->image()
                    ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'])
                    ->maxSize(1024),
            ]);
    }
}
