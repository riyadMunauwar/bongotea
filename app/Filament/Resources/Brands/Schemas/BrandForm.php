<?php

namespace App\Filament\Resources\Brands\Schemas;

use App\Filament\Concerns\HasSlugField;
use App\Models\Brand;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BrandForm
{
    use HasSlugField;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                /** There are no Brand pages; the slug is what a Brand filter names in an address. */
                Section::make('Brand')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        self::nameInput(),

                        self::slugInput(),

                        FileUpload::make('logo')
                            ->disk(Brand::LOGO_DISK)
                            ->directory(Brand::LOGO_DIRECTORY)
                            ->visibility('public')
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'])
                            ->maxSize(1024)
                            ->imagePreviewHeight('80')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
