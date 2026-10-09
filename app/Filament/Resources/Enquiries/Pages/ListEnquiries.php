<?php

namespace App\Filament\Resources\Enquiries\Pages;

use App\Filament\Resources\Enquiries\EnquiryResource;
use Filament\Resources\Pages\ListRecords;

class ListEnquiries extends ListRecords
{
    protected static string $resource = EnquiryResource::class;

    /** No create action: an Enquiry is what a buyer sent through the contact form. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
