{{--
    One level of the Menu builder: its links, each with the level beneath it
    while there is room for one (never under All Categories, which lists its
    own). `data-depth` and `data-height` are what the
    drag reads to refuse a drop past three levels.
--}}
<ul
    wire:key="menu-list-{{ $parentKey ?? 'top' }}"
    data-depth="{{ $depth }}"
    data-parent="{{ $parentKey }}"
    x-data
    x-init="
        $el.sortable ??= window.Sortable.create($el, {
            group: 'menu-items',
            draggable: '[data-key]',
            handle: '[data-handle]',
            animation: 150,
            fallbackOnBody: true,
            swapThreshold: 0.65,
            emptyInsertThreshold: 16,
            ghostClass: 'fi-sortable-ghost',
            onMove: (event) => Number(event.to.dataset.depth) - 1 + Number(event.dragged.dataset.height) <= {{ \App\Support\Menus\MenuTree::MAX_DEPTH }},
            onEnd: (event) => {
                if (event.from === event.to && event.oldIndex === event.newIndex) {
                    return
                }

                event.item.remove()
                event.from.insertBefore(event.item, event.from.children[event.oldIndex] ?? null)

                $wire.moveItem(event.item.dataset.key, event.to.dataset.parent || null, event.newIndex)
            },
        })
    "
    @class([
        'flex flex-col gap-2',
        'min-h-2 ms-8' => $depth > 1,
    ])
>
    @foreach ($items as $item)
        @php
            $about = $builder->describe($item);
            $isRefused = $builder->refusedKey === $item['key'];
        @endphp

        <li
            wire:key="menu-item-{{ $item['key'] }}"
            data-key="{{ $item['key'] }}"
            data-height="{{ $builder::height($item) }}"
            class="flex flex-col gap-2"
        >
            <div
                @class([
                    'flex items-center gap-3 rounded-lg border bg-white px-3 py-2 dark:bg-white/5',
                    'border-danger-600 dark:border-danger-400' => $isRefused,
                    'border-gray-200 dark:border-white/10' => ! $isRefused,
                ])
            >
                <span data-handle class="flex cursor-grab text-gray-400" title="Drag to move">
                    <x-filament::icon icon="heroicon-m-bars-2" class="h-5 w-5" />
                </span>

                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ $item['label'] }}</span>
                    <span class="truncate text-xs text-gray-500 dark:text-gray-400">
                        {{ $about['type'] }}@if (filled($about['target'])) · {{ $about['target'] }}@endif
                    </span>
                </div>

                @if ($isRefused)
                    <x-filament::badge color="danger" size="sm">Not saved: fix this link</x-filament::badge>
                @elseif (filled($about['warning']))
                    <x-filament::badge color="warning" size="sm">{{ $about['warning'] }}</x-filament::badge>
                @endif

                <x-filament::icon-button
                    icon="heroicon-m-pencil-square"
                    color="gray"
                    label="Edit"
                    wire:click="mountAction('editItem', {{ \Illuminate\Support\Js::from(['key' => $item['key']]) }})"
                />

                <x-filament::icon-button
                    icon="heroicon-m-trash"
                    color="danger"
                    label="Remove"
                    wire:click="mountAction('removeItem', {{ \Illuminate\Support\Js::from(['key' => $item['key']]) }})"
                />
            </div>

            @if ($depth < \App\Support\Menus\MenuTree::MAX_DEPTH && $item['type'] !== \App\Enums\MenuLinkType::AllCategories->value)
                @include('filament.resources.menus.builder-list', [
                    'builder' => $builder,
                    'items' => $item['children'],
                    'depth' => $depth + 1,
                    'parentKey' => $item['key'],
                ])
            @endif
        </li>
    @endforeach
</ul>
