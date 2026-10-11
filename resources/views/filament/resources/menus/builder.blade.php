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
    data-saved-name="{{ $builder->record->getOriginal('name') }}"
    x-on:beforeunload.window="if ($wire.hasUnsavedChanges || $wire.data.name !== $el.dataset.savedName) { $event.preventDefault(); $event.returnValue = '' }"
    class="flex flex-col gap-4"
>
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex flex-col gap-1">
            <span class="text-sm font-medium text-gray-950 dark:text-white">Links</span>
            <span class="text-sm text-gray-500 dark:text-gray-400">
                Drag a link by its handle to reorder it, or under another to nest it, up to three levels. Nothing reaches the storefront until you save.
            </span>
        </div>

        <div class="flex flex-wrap gap-2">
            <x-filament::button
                size="sm"
                icon="heroicon-m-plus"
                wire:click="mountAction('addLinks')"
            >
                Add links
            </x-filament::button>

            <x-filament::button
                size="sm"
                color="gray"
                icon="heroicon-m-link"
                wire:click="mountAction('addCustomLink')"
            >
                Custom link
            </x-filament::button>
        </div>
    </div>

    @if ($builder->footerPlaces !== [])
        <div class="flex items-start gap-2 rounded-lg bg-info-50 px-3 py-2 text-sm text-info-700 dark:bg-info-400/10 dark:text-info-400">
            <x-filament::icon icon="heroicon-m-information-circle" class="mt-0.5 h-5 w-5 shrink-0" />
            <span>
                Assigned to {{ implode(', ', $builder->footerPlaces) }}. The footer draws only the top level, so links nested beneath are not shown there.
            </span>
        </div>
    @endif

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
