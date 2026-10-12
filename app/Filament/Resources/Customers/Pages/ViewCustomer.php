<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * A Customer's own page: who they have said they are and where the tea has
 * gone, then every Order of theirs. No Edit and no Delete — the number is the
 * whole of a Customer, and every Order points at it.
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
