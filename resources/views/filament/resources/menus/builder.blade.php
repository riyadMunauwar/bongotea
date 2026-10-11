{{--
    The Menu builder's tree, drawn from the page's `tree` state. Dragging is
    SortableJS (shipped with Filament as window.Sortable): a drop is put back
    where it came from and sent to `moveItem()`, so the server's tree is the
    only one and Livewire redraws from it.
--}}
@php
    $builder = $getLivewire();
@endphp

<div
    x-data
    x-on:beforeunload.window="if ($wire.hasUnsavedChanges) { $event.preventDefault(); $event.returnValue = '' }"
    class="flex flex-col gap-4"
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex flex-col gap-1">
            <span class="text-sm font-medium text-gray-950 dark:text-white">Links</span>
            <span class="text-sm text-gray-500 dark:text-gray-400">
                Drag a link by its handle to reorder it, or under another to nest it, up to three levels. Nothing reaches the storefront until you save.
            </span>
        </div>

        <x-filament::button
            size="sm"
            icon="heroicon-m-plus"
            wire:click="mountAction('addItem')"
        >
            Add a link
        </x-filament::button>
    </div>

    @if ($builder->hasUnsavedChanges)
        <x-filament::badge color="warning" class="self-start">
            Unsaved changes
        </x-filament::badge>
    @endif

    @if ($builder->tree === [])
        <p class="text-sm text-gray-500 dark:text-gray-400">No links yet.</p>
    @endif

    @include('filament.resources.menus.builder-list', [
        'builder' => $builder,
        'items' => $builder->tree,
        'depth' => 1,
        'parentKey' => null,
    ])
</div>
