<?php

namespace App\Filament\Resources\Roles\Schemas;

use App\Enums\Permission;
use App\Models\Role;
use App\Support\Access\PermissionCatalogue;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The form where a Role is named and its Permissions ticked.
 *
 * The ticks are built from the catalogue enum and never from the
 * `permissions` table (ADR-0026), so a Permission shipped in a release the
 * Merchant has not synced is still tickable and the save makes whatever rows
 * it needs. The two headings the catalogue splits into — the working areas
 * and the acts that cannot be undone — are one Section each, so a Merchant
 * reads what they are granting rather than a flat list.
 *
 * The tick fields are not the Spatie relationship: binding a `CheckboxList`
 * to `permissions` would draw its options from the rows that exist, which is
 * exactly the table this screen must not read. They are plain fields the
 * pages fill from the record and persist through `applyPermissions()`.
 */
class RoleForm
{
    /**
     * The two tick fields, each mapped to the heading it draws under. The
     * field name is where the browser posts the chosen Permission names back.
     *
     * @var array<string, string>
     */
    private const GROUPS = [
        'working_areas' => Permission::WORKING_AREAS,
        'irreversible_acts' => Permission::IRREVERSIBLE_ACTS,
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Role')
                    ->columnSpanFull()
                    ->columns(1)
                    ->schema([
                        TextInput::make('name')
                            ->helperText('Name it after the job somebody does, so granting access is a decision you can read back later.')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true)
                            ->disabled(self::editingOwner()),

                        /**
                         * The Owner holds no Permission rows and passes every
                         * check by bypass (ADR-0024). Drawing unticked boxes
                         * for it would lie about what it may do, so it gets
                         * this note and the tick Sections below are hidden.
                         */
                        Placeholder::make('owner_note')
                            ->hiddenLabel()
                            ->content('The Owner may do everything and holds no individual Permissions. It cannot be renamed or deleted.')
                            ->visible(self::editingOwner()),
                    ]),

                ...self::permissionSections(),
            ]);
    }

    /**
     * The ticks to fill an edit form with: the Role's held Permissions split
     * back into the two fields. A held name the enum no longer declares draws
     * no box here and its row is pruned by the next sync.
     *
     * @return array<string, list<string>>
     */
    public static function ticksFor(Role $role): array
    {
        $held = $role->permissions->pluck('name')->all();

        return collect(self::GROUPS)
            ->mapWithKeys(fn (string $heading, string $field): array => [
                $field => array_values(array_intersect($held, array_keys(self::optionsFor($heading)))),
            ])
            ->all();
    }

    /**
     * Persist a Role's ticks: make any row a grant needs (ADR-0026) and set
     * the Role to exactly the chosen Permissions, then drop the cached map so
     * a Permission unticked here stops working on the next request (ADR-0025).
     *
     * @param  array<string, mixed>  $data
     */
    public static function applyPermissions(Role $role, array $data): void
    {
        $catalogue = app(PermissionCatalogue::class);

        $role->syncPermissions(array_map($catalogue->ensure(...), self::chosen($data)));

        $catalogue->forget();
    }

    /**
     * One Section per heading, each a tick list from the catalogue enum. The
     * Sections are hidden for the Owner, which draws no ticks at all.
     *
     * @return list<Section>
     */
    private static function permissionSections(): array
    {
        return collect(self::GROUPS)
            ->map(fn (string $heading, string $field): Section => Section::make($heading)
                ->columnSpanFull()
                ->hidden(self::editingOwner())
                ->schema([
                    CheckboxList::make($field)
                        ->hiddenLabel()
                        ->options(self::optionsFor($heading))
                        ->bulkToggleable(),
                ]))
            ->values()
            ->all();
    }

    /**
     * Every catalogue case under one heading, keyed by the name it is stored
     * and asked under so a tick posts back the name a grant is made from.
     *
     * @return array<string, string>
     */
    private static function optionsFor(string $heading): array
    {
        return collect(Permission::cases())
            ->filter(fn (Permission $permission): bool => $permission->group() === $heading)
            ->mapWithKeys(fn (Permission $permission): array => [$permission->value => $permission->getLabel()])
            ->all();
    }

    /**
     * Whether the form is drawing the Owner Role, which locks its name and
     * draws no ticks. Returned as a closure so each component decides it at
     * render time against the record Filament passes in.
     *
     * @return \Closure(?Role): bool
     */
    private static function editingOwner(): \Closure
    {
        return fn (?Role $record): bool => $record?->isOwner() ?? false;
    }

    /**
     * The Permissions ticked across both fields, as catalogue cases. A name is
     * resolved through `Permission::from()`, so nothing outside the catalogue
     * can be granted even if it were posted.
     *
     * @param  array<string, mixed>  $data
     * @return list<Permission>
     */
    private static function chosen(array $data): array
    {
        return collect(self::GROUPS)
            ->keys()
            ->flatMap(fn (string $field): array => $data[$field] ?? [])
            ->unique()
            ->map(fn (string $name): Permission => Permission::from($name))
            ->values()
            ->all();
    }
}
