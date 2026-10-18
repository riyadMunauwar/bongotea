{{--
    One of a Page's add-a-Block buttons, and — drawn once, under the button
    beneath the Blocks — the modal every one of them opens. A button says
    which Block it adds after; picking a tile mounts that button's own
    Filament action, then brings the new Block into view.
--}}
@php
    $modalId = "{$key}.block-picker";
    $searchId = "{$modalId}.search";

    /** What the search reads for each kind: its name and its description. */
    $searchText = fn (string $kind): string => $kind::label().' '.$kind::description();
@endphp

<div class="fi-fo-builder-block-picker">
    <div
        x-on:click="$dispatch('open-block-picker', { key: @js($key), afterItem: @js($afterItem) })"
        class="gp-block-picker-trigger"
    >
        {!! $trigger !!}
    </div>

    @if ($groups !== [])
        <div
            x-data="{
                afterItem: null,
                query: '',
                open(afterItem) {
                    this.afterItem = afterItem
                    this.query = ''
                    this.$dispatch('open-modal', { id: @js($modalId) })
                    document.getElementById(@js($modalId))?.querySelector('.fi-modal-content')?.scrollTo(0, 0)

                    if (window.matchMedia('(min-width: 1024px)').matches) {
                        setTimeout(() => document.getElementById(@js($searchId))?.focus(), 50)
                    }
                },
                matches(text) {
                    return text.toLowerCase().includes(this.query.trim().toLowerCase())
                },
                async pick(block) {
                    const builder = this.$root.closest('.fi-fo-builder')
                    const itemKeys = () => [...(builder?.querySelectorAll('.fi-fo-builder-item[x-sortable-item]') ?? [])]
                    const before = new Set(itemKeys().map((item) => item.getAttribute('x-sortable-item')))

                    this.$dispatch('close-modal', { id: @js($modalId) })

                    await this.$wire.mountAction(
                        this.afterItem === null ? @js($addActionName) : @js($addBetweenActionName),
                        this.afterItem === null ? { block } : { block, afterItem: this.afterItem },
                        { schemaComponent: @js($key) },
                    )
                    await this.$nextTick()

                    itemKeys()
                        .find((item) => ! before.has(item.getAttribute('x-sortable-item')))
                        ?.scrollIntoView({ behavior: 'smooth', block: 'center' })
                },
            }"
            x-on:open-block-picker.window="if ($event.detail.key === @js($key)) open($event.detail.afterItem)"
        >
            <x-filament::modal
                :id="$modalId"
                teleport="body"
                width="5xl"
                heading="Add a Block"
                :autofocus="false"
                class="gp-block-picker"
            >
                <x-slot name="header">
                    <div class="gp-block-picker-header">
                        <h2 id="{{ $modalId }}.heading" class="fi-modal-heading">Add a Block</h2>

                        <x-filament::input.wrapper :prefix-icon="\Filament\Support\Icons\Heroicon::MagnifyingGlass">
                            <x-filament::input
                                type="search"
                                :id="$searchId"
                                x-model="query"
                                placeholder="Search Blocks"
                                aria-label="Search Blocks"
                                autocomplete="off"
                            />
                        </x-filament::input.wrapper>
                    </div>
                </x-slot>

                @foreach ($groups as $group)
                    @php
                        $texts = array_map($searchText, $group['kinds']);
                    @endphp

                    <section x-show="@js($texts).some((text) => matches(text))" class="gp-block-picker-group">
                        <h3 class="gp-block-picker-group-label">{{ $group['label'] }}</h3>

                        <div class="gp-block-picker-tiles">
                            @foreach ($group['kinds'] as $kind)
                                <button
                                    type="button"
                                    x-show="matches(@js($searchText($kind)))"
                                    x-on:click="pick(@js($kind::type()))"
                                    x-tooltip="{ content: @js($kind::description()), theme: $store.theme, touch: ['hold', 400] }"
                                    aria-describedby="{{ $modalId }}.{{ $kind::type() }}"
                                    class="gp-block-picker-tile"
                                >
                                    <x-filament::icon :icon="$kind::icon()" class="gp-block-picker-tile-icon" />
                                    <span class="gp-block-picker-tile-label">{{ $kind::label() }}</span>
                                    <span id="{{ $modalId }}.{{ $kind::type() }}" class="sr-only">{{ $kind::description() }}</span>
                                </button>
                            @endforeach
                        </div>
                    </section>
                @endforeach

                @php
                    $allTexts = array_map($searchText, array_merge(...array_column($groups, 'kinds')));
                @endphp

                <p x-show="! @js($allTexts).some((text) => matches(text))" x-cloak class="gp-block-picker-empty">
                    No Block matches "<span x-text="query.trim()"></span>".
                </p>
            </x-filament::modal>
        </div>
    @endif
</div>
