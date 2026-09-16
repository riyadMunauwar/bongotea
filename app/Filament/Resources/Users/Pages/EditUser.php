<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Access\UserGuard;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * The Roles are read off the record and folded into the tick field the
     * checkboxes bind to, since they are not a stored column on the User.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->record;

        $data['roles'] = UserForm::heldRoleIds($user);

        return $data;
    }

    /**
     * The name and email are written; the Roles are synced through the form.
     *
     * The two lockout guards run here as the server-side truth rather than
     * only as UI: a crafted save cannot demote the last Owner, and nobody
     * empties their own Roles, whatever the browser sent. A refusal is shown
     * and the save is halted with the transaction rolled back, so nothing is
     * written.
     *
     * @param  User  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var list<int|string> $roleIds */
        $roleIds = $data['roles'] ?? [];

        $refusal = app(UserGuard::class)->refusalForRoles(
            $this->currentUser(),
            $record,
            $roleIds,
        );

        if ($refusal !== null) {
            Notification::make()
                ->danger()
                ->title('That change is not allowed')
                ->body($refusal)
                ->send();

            $this->halt(shouldRollbackDatabaseTransaction: true);
        }

        $record->update([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        UserForm::applyRoles($record, $data);

        return $record;
    }

    /**
     * Emptying a User's Roles suspends them — a real state with real
     * consequences — so a save that would do it asks first rather than
     * letting it happen as a side effect of an edit made for another reason.
     * The Roles field is `live()`, so this re-evaluates as the last box is
     * unticked and the save button turns into a confirmation.
     */
    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->requiresConfirmation(fn (): bool => $this->willSuspendOnSave())
            ->modalHeading('Suspend this User?')
            ->modalDescription('Saving with no Roles suspends this User: they cannot sign in, but they stay in the list and everything they did keeps their name on it. Give them a Role and they are back at once.')
            ->modalSubmitActionLabel('Suspend');
    }

    /**
     * Whether saving the form as it stands would suspend the User — that is,
     * whether it holds no Roles. Public because it is what the save action's
     * confirmation turns on, and asserting it is how the confirmation is held
     * to the state that should raise it.
     */
    public function willSuspendOnSave(): bool
    {
        return blank($this->data['roles'] ?? []);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
