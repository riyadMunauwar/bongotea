<?php

namespace App\Filament\Resources\Menus\Pages;

use App\Enums\MenuLinkType;
use App\Enums\PageStatus;
use App\Filament\Resources\Menus\MenuResource;
use App\Models\Menu;
use App\Models\Page;
use App\Support\Menus\MenuTree;
use App\Support\Menus\MenuTreeRefused;
use App\Support\Storefront\StorefrontMenus;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;

/**
 * The Menu builder: the name, and the tree of links beneath it.
 *
 * The tree is held as this page's state and nothing reaches the storefront
 * until Save, which hands the whole of it to `MenuTree::save()` — the guard
 * that refuses a tree too deep, a target that does not fit its type, a Page
 * that is gone or an address the storefront would not draw. A refusal names
 * the link and marks its row, and leaves the name unsaved too.
 *
 * Every node carries a `key` that lives only here, so a drag, an edit or a
 * removal names the node it means however the tree has moved since it was
 * drawn. The drag itself is SortableJS, which Filament already ships, with
 * the three-level limit checked while dragging; `moveItem()` checks it again.
 *
 * @phpstan-type BuilderItem array{key: string, label: string, type: string, note: ?string, target_id: ?int, anchor: ?string, url: ?string, children: list<mixed>}
 *
 * @property Menu $record
 */
class EditMenu extends EditRecord
{
    protected static string $resource = MenuResource::class;

    /**
     * @var list<BuilderItem>
     */
    public array $tree = [];

    /** The key of the link the last refused Save pointed at. */
    public ?string $refusedKey = null;

    public bool $hasUnsavedChanges = false;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->loadTree();
    }

    public function form(Schema $schema): Schema
    {
        return parent::form($schema)
            ->columns(1)
            ->components([
                ...$schema->getComponents(withHidden: true),
                View::make('filament.resources.menus.builder'),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('discard')
                ->label('Discard changes')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('The links go back to how they were last saved.')
                ->visible(fn (): bool => $this->hasUnsavedChanges)
                ->action(fn () => $this->loadTree()),

            MenuResource::refuseDeletingAssigned(DeleteAction::make()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** Filled, not saved: `MenuTree::save()` writes the name with the tree once the tree has passed. */
        $record->fill($data);

        try {
            MenuTree::save($record, $this->tree, auth()->user());
        } catch (MenuTreeRefused $refused) {
            $this->refusedKey = $this->keyAt($refused->path);

            Notification::make()
                ->danger()
                ->title('Menu not saved')
                ->body($refused->getMessage())
                ->send();

            throw (new Halt)->rollBackDatabaseTransaction();
        }

        $this->refusedKey = null;
        $this->hasUnsavedChanges = false;

        return $record;
    }

    /**
     * Where a drag dropped a link: under `$parentKey` (null for the top
     * level) at `$index` among its new siblings. A move that would take the
     * branch past three levels, or into itself, is refused and nothing moves.
     */
    public function moveItem(string $key, ?string $parentKey, int $index): void
    {
        $node = $this->find($this->tree, $key);

        if ($node === null || ($parentKey !== null && ($parentKey === $key || $this->find($node['children'], $parentKey) !== null))) {
            return;
        }

        $depth = $parentKey === null ? 0 : $this->depthOf($parentKey);

        if ($depth === null || $depth + self::height($node) > MenuTree::MAX_DEPTH) {
            Notification::make()
                ->warning()
                ->title('A Menu can be at most '.MenuTree::MAX_DEPTH.' levels deep')
                ->send();

            return;
        }

        $tree = $this->without($this->tree, $key);
        $this->tree = $this->insert($tree, $parentKey, $index, $node);
        $this->hasUnsavedChanges = true;
    }

    public function addItemAction(): Action
    {
        return Action::make('addItem')
            ->label('Add a link')
            ->icon(Heroicon::OutlinedPlus)
            ->slideOver()
            ->modalHeading('Add a link')
            ->modalSubmitActionLabel('Add')
            ->schema(self::itemFields())
            ->action(function (array $data): void {
                $this->tree[] = [...self::item($data), 'key' => (string) Str::uuid(), 'children' => []];
                $this->hasUnsavedChanges = true;
            });
    }

    public function editItemAction(): Action
    {
        return Action::make('editItem')
            ->slideOver()
            ->modalHeading('Edit link')
            ->modalSubmitActionLabel('Done')
            ->fillForm(fn (array $arguments): array => $this->find($this->tree, $arguments['key'] ?? '') ?? [])
            ->schema(self::itemFields())
            ->action(function (array $data, array $arguments): void {
                $this->tree = $this->replace($this->tree, $arguments['key'] ?? '', fn (array $node): array => [...$node, ...self::item($data)]);
                $this->hasUnsavedChanges = true;

                if ($this->refusedKey === ($arguments['key'] ?? null)) {
                    $this->refusedKey = null;
                }
            });
    }

    public function removeItemAction(): Action
    {
        $node = fn (array $arguments): ?array => $this->find($this->tree, $arguments['key'] ?? '');

        return Action::make('removeItem')
            ->color('danger')
            ->requiresConfirmation(fn (array $arguments): bool => ($node($arguments)['children'] ?? []) !== [])
            ->modalHeading(fn (array $arguments): string => 'Remove “'.($node($arguments)['label'] ?? '').'”?')
            ->modalDescription(fn (array $arguments): string => 'The '.self::countAll($node($arguments)['children'] ?? []).' links beneath it are removed with it.')
            ->modalSubmitActionLabel('Remove')
            ->action(function (array $arguments): void {
                $this->tree = $this->without($this->tree, $arguments['key'] ?? '');
                $this->hasUnsavedChanges = true;
            });
    }

    /**
     * How a row says what it points at, at a glance, and whether the
     * storefront would leave it out.
     *
     * @param  BuilderItem  $node
     * @return array{type: string, target: ?string, warning: ?string}
     */
    public function describe(array $node): array
    {
        $type = MenuLinkType::tryFrom($node['type']);
        $page = $node['target_id'] === null ? null : ($this->pages[$node['target_id']] ?? null);

        $target = match ($type) {
            MenuLinkType::Page => $page['title'] ?? null,
            MenuLinkType::Anchor => ($page['title'] ?? 'Home').' #'.$node['anchor'],
            MenuLinkType::Url => $node['url'],
            default => null,
        };

        $warning = match (true) {
            $node['target_id'] !== null && $page === null => 'Page deleted',
            $page !== null && ! $page['published'] => 'Page not published: hidden on the storefront',
            $type === MenuLinkType::Heading && $node['children'] === [] => 'No links beneath: hidden on the storefront',
            default => null,
        };

        return ['type' => $type?->getLabel() ?? $node['type'], 'target' => $target, 'warning' => $warning];
    }

    /**
     * The tallest branch below and including this node, so the browser can
     * refuse a drop that would take it past three levels.
     *
     * @param  array{children: list<mixed>}  $node
     */
    public static function height(array $node): int
    {
        $tallest = 0;

        foreach ($node['children'] as $child) {
            $tallest = max($tallest, self::height($child));
        }

        return 1 + $tallest;
    }

    /**
     * Every Page a link may name, by id: its title and whether it is live.
     *
     * @return array<int, array{title: string, published: bool}>
     */
    #[Computed]
    public function pages(): array
    {
        return Page::query()->get(['id', 'title', 'status'])
            ->mapWithKeys(fn (Page $page): array => [$page->id => ['title' => $page->title, 'published' => $page->status === PageStatus::Published]])
            ->all();
    }

    private function loadTree(): void
    {
        $this->tree = self::withKeys(MenuTree::of($this->record));
        $this->refusedKey = null;
        $this->hasUnsavedChanges = false;
    }

    /**
     * @return list<Component>
     */
    private static function itemFields(): array
    {
        $typeIs = fn (MenuLinkType ...$types): Closure => fn (Get $get): bool => in_array($get('type'), array_map(fn (MenuLinkType $type): string => $type->value, $types), true);

        return [
            TextInput::make('label')
                ->label('Label')
                ->required()
                ->maxLength(MenuTree::MAX_LABEL),

            Select::make('type')
                ->label('Points at')
                ->options(collect(MenuLinkType::cases())->mapWithKeys(fn (MenuLinkType $type): array => [$type->value => $type->getLabel()])->all())
                ->default(MenuLinkType::Page->value)
                ->required()
                ->live(),

            Select::make('target_id')
                ->label(fn (Get $get): string => $get('type') === MenuLinkType::Anchor->value ? 'On page' : 'Page')
                ->options(fn (): array => Page::query()->orderBy('title')->pluck('title', 'id')->all())
                ->searchable()
                ->visible($typeIs(MenuLinkType::Page, MenuLinkType::Anchor))
                ->required($typeIs(MenuLinkType::Page))
                ->helperText(fn (Get $get): ?string => $get('type') === MenuLinkType::Anchor->value ? 'Leave empty for the home page.' : 'Hidden on the storefront while the page is a draft.'),

            TextInput::make('anchor')
                ->label('Anchor')
                ->prefix('#')
                ->regex(MenuTree::ANCHOR)
                ->validationMessages(['regex' => 'Letters, digits, hyphens and underscores only.'])
                ->visible($typeIs(MenuLinkType::Anchor))
                ->required($typeIs(MenuLinkType::Anchor))
                ->helperText('The anchor a block on that page was given.'),

            TextInput::make('url')
                ->label('Address')
                ->regex(StorefrontMenus::ALLOWED_URL)
                ->validationMessages(['regex' => 'Start with / for this site, or https://, tel: or mailto:.'])
                ->maxLength(MenuTree::MAX_URL)
                ->visible($typeIs(MenuLinkType::Url))
                ->required($typeIs(MenuLinkType::Url)),

            TextInput::make('note')
                ->label('Note')
                ->maxLength(MenuTree::MAX_NOTE)
                ->helperText('A short line under the label in a dropdown on a computer. Optional.'),
        ];
    }

    /**
     * A slide-over's fields as a node holds them, with the targets its type
     * does not read blanked.
     *
     * @param  array<string, mixed>  $data
     * @return array{label: string, type: string, note: ?string, target_id: ?int, anchor: ?string, url: ?string}
     */
    private static function item(array $data): array
    {
        $type = MenuLinkType::tryFrom((string) ($data['type'] ?? ''));

        return [
            'label' => (string) ($data['label'] ?? ''),
            'type' => $type->value ?? (string) ($data['type'] ?? ''),
            'note' => filled($data['note'] ?? null) ? (string) $data['note'] : null,
            'target_id' => in_array($type, [MenuLinkType::Page, MenuLinkType::Anchor], true) && is_numeric($data['target_id'] ?? null) ? (int) $data['target_id'] : null,
            'anchor' => $type === MenuLinkType::Anchor ? ltrim((string) ($data['anchor'] ?? ''), '#') : null,
            'url' => $type === MenuLinkType::Url ? (string) ($data['url'] ?? '') : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<BuilderItem>
     */
    private static function withKeys(array $items): array
    {
        return array_map(fn (array $item): array => [
            ...$item,
            'key' => (string) Str::uuid(),
            'children' => self::withKeys($item['children']),
        ], $items);
    }

    /**
     * @param  list<array{children: list<mixed>}>  $items
     */
    private static function countAll(array $items): int
    {
        return array_sum(array_map(fn (array $item): int => 1 + self::countAll($item['children']), $items));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return BuilderItem|null
     */
    private function find(array $items, string $key): ?array
    {
        foreach ($items as $item) {
            if ($item['key'] === $key) {
                return $item;
            }

            $found = $this->find($item['children'], $key);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The level a node sits at, 1 for the top, or null when it is not in the
     * tree.
     *
     * @param  list<array<string, mixed>>|null  $items
     */
    private function depthOf(string $key, ?array $items = null, int $depth = 1): ?int
    {
        foreach ($items ?? $this->tree as $item) {
            if ($item['key'] === $key) {
                return $depth;
            }

            $found = $this->depthOf($key, $item['children'], $depth + 1);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The key of the node at a path of indexes, as `MenuTreeRefused` names one.
     *
     * @param  list<int>  $path
     */
    private function keyAt(array $path): ?string
    {
        $items = $this->tree;
        $node = null;

        foreach ($path as $index) {
            $node = array_values($items)[$index] ?? null;

            if (! is_array($node)) {
                return null;
            }

            $items = $node['children'] ?? [];
        }

        return $node['key'] ?? null;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<BuilderItem>
     */
    private function without(array $items, string $key): array
    {
        $kept = [];

        foreach ($items as $item) {
            if ($item['key'] !== $key) {
                $kept[] = [...$item, 'children' => $this->without($item['children'], $key)];
            }
        }

        return $kept;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  BuilderItem  $node
     * @return list<BuilderItem>
     */
    private function insert(array $items, ?string $parentKey, int $index, array $node): array
    {
        if ($parentKey === null) {
            array_splice($items, max(0, min($index, count($items))), 0, [$node]);

            return $items;
        }

        return array_map(fn (array $item): array => [
            ...$item,
            'children' => $item['key'] === $parentKey
                ? $this->insert($item['children'], null, $index, $node)
                : $this->insert($item['children'], $parentKey, $index, $node),
        ], $items);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  Closure(BuilderItem): BuilderItem  $change
     * @return list<BuilderItem>
     */
    private function replace(array $items, string $key, Closure $change): array
    {
        return array_map(fn (array $item): array => $item['key'] === $key
            ? $change($item)
            : [...$item, 'children' => $this->replace($item['children'], $key, $change)], $items);
    }
}
