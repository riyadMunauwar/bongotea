<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Support\Access\UserGuard;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

/**
 * The people who can sign in, and what each may reach.
 *
 * A filed-away User is off the default list — the soft-delete scope takes
 * them out — and back under the Trashed filter, where they can be brought
 * back. There is no force-delete: erasing the row would null the attribution
 * on the Stock Counts and Adjustments they made, and filing away is what
 * keeps their name on that history while locking them out for good.
 *
 * Filing away and bringing back are one row at a time, never a bulk action:
 * suspending or removing several people's access with one click is exactly
 * the accident these guards exist to prevent — the same reason the Roles
 * screen offers no bulk delete.
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                /**
                 * Every Role the User holds, as a badge each. No badge at all
                 * is Suspended: they hold none and cannot sign in, which the
                 * placeholder says in place of an empty cell.
                 */
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->placeholder('Suspended'),

                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),

                /**
                 * Filing away is a soft delete, refused for the last Owner so
                 * the installation is never left without one. The refusal says
                 * why, in place of the delete.
                 */
                DeleteAction::make()
                    ->label('File away')
                    ->modalHeading('File this User away?')
                    ->modalDescription('They stop being able to sign in and drop off this list, but everything they did keeps their name on it, and you can bring them back later.')
                    ->modalSubmitActionLabel('File away')
                    ->before(function (User $record, DeleteAction $action): void {
                        $refusal = app(UserGuard::class)->refusalForFilingAway($record);

                        if ($refusal !== null) {
                            Notification::make()
                                ->danger()
                                ->title('This User cannot be filed away')
                                ->body($refusal)
                                ->send();

                            $action->cancel();
                        }
                    }),

                RestoreAction::make()
                    ->label('Bring back'),
            ]);
    }
}
