<?php

namespace App\Filament\Resources\Enquiries\Pages;

use App\Filament\Resources\Enquiries\Actions\MoveEnquiryAction;
use App\Filament\Resources\Enquiries\EnquiryResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEnquiry extends ViewRecord
{
    protected static string $resource = EnquiryResource::class;

    protected function getHeaderActions(): array
    {
        return MoveEnquiryAction::all();
    }
}
