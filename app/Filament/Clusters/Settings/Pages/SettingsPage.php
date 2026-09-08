<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Support\Settings\SettingsGroup;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;

/**
 * One form editing one Settings group.
 *
 * A concrete page names its group in `$group` and lays out the fields in
 * `formComponents()`; this base does everything the two have in common. It
 * gates the page on the cluster's permission, fills the form from the group
 * as the installation has it — config defaults under the saved row — and on
 * save rebuilds the group from the validated state and writes it through the
 * group class, which is also what makes the new values the ones the rest of
 * this request reads.
 *
 * The form is keyed by property name, so a field is a group property and
 * nothing maps between the two. A value arrives as the browser typed it and
 * `SettingsGroup::fromArray()` coerces it to the property's declared type.
 */
abstract class SettingsPage extends Page
{
    protected static ?string $cluster = SettingsCluster::class;

    /**
     * The group this page edits.
     *
     * @var class-string<SettingsGroup>
     */
    protected static string $group;

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    /**
     * @return array<Component | Action>
     */
    abstract protected function formComponents(): array;

    public static function canAccess(): bool
    {
        return SettingsCluster::canAccess();
    }

    /**
     * The group's public properties, read straight off the instance rather
     * than through `toArray()`, which is the row's shape and seals secrets.
     * A page for a group that declares `secrets()` must not fill them this
     * way either: a secret never round-trips to the browser (ADR-0012), so
     * that page owes its own masking before the first one is built.
     */
    public function mount(): void
    {
        $this->form->fill(get_object_vars(app(static::$group)));
    }

    /**
     * One column, so each section takes a row of its own and the page reads
     * top to bottom as a list of settled things. That differs from the
     * resource forms, whose sections pair up; a section here lays its own
     * fields out with its own `columns()`.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components($this->formComponents())
            ->columns(1)
            ->statePath('data');
    }

    /**
     * The same shape Filament's own record pages build: the form, and its
     * actions in a footer. A page wanting a second action adds it to
     * `getFormActions()` and leaves this alone.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make($this->getFormActions())
                        ->alignment($this->getFormActionsAlignment())
                        ->sticky($this->areFormActionsSticky())
                        ->key('form-actions'),
                ]),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $previous = app(static::$group);
        $saved = static::$group::fromArray($this->form->getState())->save();

        $this->logChanges($previous, $saved);

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }

    /**
     * One activity entry per save that changed something: the acting User,
     * the group by key, and the changed properties in the same
     * `old`/`attributes` shape a logged model writes, so the entry reads like
     * any other. A save that changes nothing leaves no entry, so the log
     * answers "who changed the currency and when" without noise from a resave.
     */
    protected function logChanges(SettingsGroup $previous, SettingsGroup $saved): void
    {
        $changes = $saved->changesSince($previous);

        if ($changes['attributes'] === []) {
            return;
        }

        $key = static::$group::key();

        activity()
            ->causedBy(auth()->user())
            ->event('updated')
            ->withProperty('group', $key)
            ->withChanges($changes)
            ->log("Saved the {$key} settings");
    }
}
