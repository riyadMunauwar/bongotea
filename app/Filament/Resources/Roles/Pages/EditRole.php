<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Roles\Schemas\RoleForm;
use App\Models\Role;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * The ticks are not stored on the Role, so they are read off its held
     * Permissions and folded into the two form fields the checkboxes bind to.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Role $role */
        $role = $this->record;

        return array_merge($data, RoleForm::ticksFor($role));
    }

    /**
     * The Owner is locked: its name field is disabled and its tick Sections
     * are hidden, so nothing to write reaches here — but the guard is explicit
     * so a crafted request cannot rename it or hang Permissions off it either.
     *
     * @param  Role  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ($record->isOwner()) {
            return $record;
        }

        $record->update(['name' => $data['name']]);

        RoleForm::applyPermissions($record, $data);

        return $record;
    }
}
