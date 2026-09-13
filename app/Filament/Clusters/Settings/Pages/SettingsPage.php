<?php

namespace App\Filament\Clusters\Settings\Pages;

use App\Filament\Clusters\Settings\SettingsCluster;
use App\Support\Settings\SettingsGroup;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use LogicException;

/**
 * One form editing one Settings group.
 *
 * A concrete page names its group in `$group` and lays out the fields in
 * `formComponents()`; this base does everything the two have in common. It
 * gates the page on the cluster's permission, fills the form from the group
 * as the installation has it — config defaults under the saved row — and on
 * save lays the validated state over that group and writes it through the
 * group class, which is also what makes the new values the ones the rest of
 * this request reads.
 *
 * The form is keyed by property name, so a field is a group property and
 * nothing maps between the two. A value arrives as the browser typed it and
 * `SettingsGroup::with()` coerces it to the property's declared type.
 *
 * A property the group names in `secrets()` never round-trips to the browser
 * (ADR-0012). The page reads that list off the group and does the rest: its
 * field becomes a password input that loads blank, with a hint when a value
 * is stored; blank on save keeps the stored value, a typed value replaces it.
 * A concrete page declares an ordinary `TextInput` for it and nothing more.
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

    public function mount(): void
    {
        $this->form->fill($this->formValues());
    }

    /**
     * What the form opens on: the group's public properties, read straight off
     * the instance rather than through `toArray()`, which is the row's shape
     * and seals secrets — with every secret blanked before it reaches Livewire
     * state, so the stored value is in neither the component's snapshot nor
     * the HTML.
     *
     * A page whose field is not the property as the group holds it overrides
     * this, and has to do it here rather than after `mount()`: filling is when
     * a component that reshapes its own state gets to (a Repeater keys its
     * rows then), and a value written afterwards has missed it.
     *
     * @return array<string, mixed>
     */
    protected function formValues(): array
    {
        return [
            ...get_object_vars(app(static::$group)),
            ...array_fill_keys(static::$group::secrets(), null),
        ];
    }

    /**
     * One column, so each section takes a row of its own and the page reads
     * top to bottom as a list of settled things. That differs from the
     * resource forms, whose sections pair up; a section here lays its own
     * fields out with its own `columns()`.
     */
    public function form(Schema $schema): Schema
    {
        $schema
            ->components($this->formComponents())
            ->columns(1)
            ->statePath('data');

        foreach ($schema->getFlatFields(withHidden: true) as $field) {
            if (! static::$group::isSecret($field->getName())) {
                continue;
            }

            if (! $field instanceof TextInput) {
                throw new LogicException("The secret [{$field->getName()}] on ".static::class.' must be a TextInput, so it can be made write-only.');
            }

            $this->makeWriteOnly($field);
        }

        return $schema;
    }

    /**
     * A secret's field: masked, kept out of browser autofill, and carrying a
     * hint when a value is stored so the blank does not read as "unset". The
     * hint is a closure because it reads the group as it stands when the
     * form is drawn, not when the schema was built. Revealing shows only what
     * the User has just typed; the stored value is never in the field.
     */
    protected function makeWriteOnly(TextInput $field): void
    {
        $name = $field->getName();

        $field
            ->password()
            ->revealable()
            ->autocomplete('new-password')
            ->hint(fn (): ?string => app(static::$group)->hasSecret($name) ? 'A value is stored. Leave blank to keep it.' : null);
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
        $previous = app(static::$group);
        $saved = $previous->with($this->valuesToSave())->save();

        $this->logChanges($previous, $saved);

        /** A secret just typed is now the stored value, and leaves Livewire state the same way it never entered it. */
        foreach (static::$group::secrets() as $name) {
            data_set($this->data, $name, null);
        }

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }

    /**
     * The validated form state, less every secret left blank: `with()` then
     * keeps the stored value for those, and a typed value replaces it.
     *
     * @return array<string, mixed>
     */
    protected function valuesToSave(): array
    {
        $values = $this->form->getState();

        foreach (static::$group::secrets() as $name) {
            if (blank($values[$name] ?? null)) {
                unset($values[$name]);
            }
        }

        return $values;
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
