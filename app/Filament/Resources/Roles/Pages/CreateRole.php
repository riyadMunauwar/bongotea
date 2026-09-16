<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Models\Role;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * The name is the only column; the ticks are two fields the form owns
     * rather than model attributes, so they are set through the catalogue
     * rather than mass-assigned onto the Role.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $role = Role::create(['name' => $data['name']]);

        RoleForm::applyPermissions($role, $data);

        return $role;
    }
}
