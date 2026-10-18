<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Support\Pages\PageBlocks;
use Filament\Actions\Action;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Builder\Block;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;

/**
 * A Page's Blocks, added through a modal of icon tiles rather than
 * Filament's dropdown list.
 *
 * Every add button — the one under the Blocks and the one between each two —
 * opens the same modal, which lays the kinds out by group with a search
 * across their names and descriptions. Picking a tile mounts the button's own
 * Filament action, so where the Block goes and how it is filled in are
 * Filament's as before; only the choosing is drawn here.
 */
class BlocksBuilder extends Builder
{
    /**
     * @param  array<Block>  $blocks
     */
    protected function generateBlockPickerHtml(
        Action $action,
        array $blocks,
        string $key,
        string $triggerHtml,
        Alignment|string|null $actionAlignment = null,
        ?string $afterItem = null,
        array|int|null $columns = null,
        Width|string|null $width = null,
    ): string {
        return view('filament.resources.pages.block-picker', [
            'key' => $key,
            'trigger' => $triggerHtml,
            'afterItem' => $afterItem,
            'addActionName' => $this->getAddActionName(),
            'addBetweenActionName' => $this->getAddBetweenActionName(),
            'groups' => $afterItem === null
                ? PageBlocks::pickerGroups(array_values(array_map(fn (Block $block): string => $block->getName(), $blocks)))
                : [],
        ])->render();
    }
}
