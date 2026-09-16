<?php

namespace App\Filament\Resources\Roles\Tables;

use App\Models\Role;
use App\Support\Access\PermissionCatalogue;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The Roles, each named after a job and showing how many hold it.
 *
 * There is no bulk delete on purpose: deleting a Role is refused while
 * anybody holds it, and that guard only means anything one Role at a time —
 * one click must never revoke several people's access at once.
 */
class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                /**
                 * How many Users hold this Role, which is the blast radius of
                 * an edit and the reason a delete may be refused.
                 */
                TextColumn::make('users_count')
                    ->label('Users')
                    ->counts('users')
                    ->badge()
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),

                /**
                 * Hidden for the Owner, which cannot be deleted; refused for
                 * any Role a User still holds, with the count so the Merchant
                 * knows what they would be revoking. Unticking a Permission
                 * needs no such guard — it takes effect on the next request.
                 */
                DeleteAction::make()
                    ->visible(fn (Role $record): bool => ! $record->isOwner())
                    ->before(function (Role $record, DeleteAction $action): void {
                        $holders = $record->users()->count();

                        if ($holders > 0) {
                            Notification::make()
                                ->danger()
                                ->title('This Role is still held')
                                ->body(self::holdersSentence($holders))
                                ->send();

                            $action->cancel();
                        }
                    })
                    /**
                     * Deleting a Role takes its rows out of the pivot, so it
                     * is a Role-touching write like any other and ends by
                     * dropping the cached Permission map (ADR-0025).
                     */
                    ->after(fn () => app(PermissionCatalogue::class)->forget()),
            ]);
    }

    /**
     * How many hold the Role, and what to do before it can go.
     */
    private static function holdersSentence(int $holders): string
    {
        $count = $holders === 1
            ? '1 User still holds this Role'
            : "{$holders} Users still hold this Role";

        return "{$count}. Move them to another Role before you delete it.";
    }
}
