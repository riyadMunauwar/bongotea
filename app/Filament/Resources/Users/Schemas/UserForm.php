<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\Role;
use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

/**
 * The form where a User is named, given a way in, and handed their Roles.
 *
 * The Roles field is not the Spatie relationship: binding it would let the
 * save write the pivot on its own, ahead of the guards that must run first —
 * the last Owner cannot be demoted, and nobody empties their own Roles. So it
 * is a plain field the pages fill from the record and persist through
 * `applyRoles()` once `UserGuard` has allowed it.
 *
 * The password is the initial one, set once and handed over out of band; the
 * application sends no email. It shows only when a User is being added — a
 * User rotates their own password from the profile page, never the Owner, so
 * there is deliberately no password field on the edit screen for somebody
 * else to reset from.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Person')
                    ->columnSpanFull()
                    ->columns(1)
                    ->schema([
                        TextInput::make('name')
                            ->helperText('The name that reads back on the Stock Counts and stock movements they make.')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->helperText('What they sign in with. The installation sends no mail, so tell them their password another way.')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            ->unique(User::class, ignoreRecord: true),

                        TextInput::make('password')
                            ->helperText('The password they first sign in with. They can change it themselves from their profile.')
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->required()
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->visibleOn('create'),
                    ]),

                Section::make('Roles')
                    ->description('A User may hold any number; what they may do is everything their Roles allow between them. A User with no Roles is suspended — they cannot sign in, but they stay in the list and come back the moment a Role is given.')
                    ->columnSpanFull()
                    ->columns(1)
                    ->schema([
                        CheckboxList::make('roles')
                            ->hiddenLabel()
                            ->options(self::roleOptions())
                            ->bulkToggleable()
                            /**
                             * Live so the save button can tell, as the boxes
                             * are unticked, whether the save is about to
                             * suspend the User and needs to ask first.
                             */
                            ->live(),
                    ]),
            ]);
    }

    /**
     * The Role ids a User holds, to fill an edit form with.
     *
     * @return list<int>
     */
    public static function heldRoleIds(User $user): array
    {
        return $user->roles->pluck('id')->map(fn (int|string $id): int => (int) $id)->all();
    }

    /**
     * Set a User to exactly the chosen Roles.
     *
     * Assigning a User a Role changes nothing in the Role→Permission map, so
     * unlike composing a Role this does not touch the catalogue cache: what
     * this User may do is re-read from their Roles on their next request.
     *
     * @param  array<string, mixed>  $data
     */
    public static function applyRoles(User $user, array $data): void
    {
        $user->syncRoles(self::chosenRoles($data));
    }

    /**
     * The Roles ticked in the form, as models.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, Role>
     */
    public static function chosenRoles(array $data): Collection
    {
        /** @var list<int|string> $ids */
        $ids = $data['roles'] ?? [];

        return Role::query()->whereKey($ids)->get();
    }

    /**
     * Every Role, keyed by id, to draw the ticks from. The Owner is offered
     * like any other: promoting somebody to Owner, or demoting them, is done
     * by giving or taking it here.
     *
     * @return array<int, string>
     */
    private static function roleOptions(): array
    {
        return Role::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
