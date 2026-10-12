<?php

namespace Database\Seeders\Concerns;

use App\Enums\MenuLinkType;
use App\Models\Menu;
use App\Models\Setting;
use App\Support\Menus\MenuTree;
use App\Support\Settings\FooterSettings;
use App\Support\Settings\HeaderSettings;

/**
 * Builds the header's and footer's Menus and assigns them to their places
 * (ADR-0033), every link to an installed Page or Category naming it, so a
 * renamed slug is followed rather than broken.
 *
 * Only the Menu ids are written into the header's and footer's rows: their
 * other settings default to the mockup's copy, and a row would freeze
 * today's defaults over any later change to them (`.ai/rules/settings.md`).
 */
trait AssignsMenus
{
    /**
     * @param  list<array<string, mixed>>  $links
     */
    private static function assignHeader(array $links): void
    {
        $header = Menu::query()->create(['name' => 'Main menu']);
        MenuTree::save($header, $links);

        $row = Setting::query()->find(HeaderSettings::key());
        Setting::query()->updateOrCreate(['key' => HeaderSettings::key()], ['value' => [...($row->value ?? []), 'menuId' => $header->id]]);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $columns  each column's links under its heading
     * @param  list<array<string, mixed>>  $bottom  the bottom line's links; none leaves it unassigned
     */
    private static function assignFooter(array $columns, array $bottom = []): void
    {
        $assigned = [];

        foreach ($columns as $heading => $links) {
            $column = Menu::query()->create(['name' => "Footer — {$heading}"]);
            MenuTree::save($column, $links);

            $assigned[] = ['heading' => $heading, 'menuId' => $column->id];
        }

        $bottomMenuId = null;

        if ($bottom !== []) {
            $bottomMenu = Menu::query()->create(['name' => 'Footer bottom line']);
            MenuTree::save($bottomMenu, $bottom);
            $bottomMenuId = $bottomMenu->id;
        }

        $row = Setting::query()->find(FooterSettings::key());
        Setting::query()->updateOrCreate(['key' => FooterSettings::key()], ['value' => [...($row->value ?? []), 'columns' => $assigned, 'bottomMenuId' => $bottomMenuId]]);
    }

    /**
     * @return array{label: string, type: string, target_id: int, note: ?string}
     */
    private static function pageLink(string $label, int $pageId, ?string $note = null): array
    {
        return ['label' => $label, 'type' => MenuLinkType::Page->value, 'target_id' => $pageId, 'note' => $note];
    }

    /**
     * @return array{label: string, type: string, target_id: int, anchor: string, note: ?string}
     */
    private static function anchorLink(string $label, int $pageId, string $anchor, ?string $note = null): array
    {
        return ['label' => $label, 'type' => MenuLinkType::Anchor->value, 'target_id' => $pageId, 'anchor' => $anchor, 'note' => $note];
    }

    /**
     * @return array{label: string, type: string, target_id: int, note: ?string}
     */
    private static function categoryLink(string $label, int $categoryId, ?string $note = null): array
    {
        return ['label' => $label, 'type' => MenuLinkType::Category->value, 'target_id' => $categoryId, 'note' => $note];
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return array{label: string, type: string, children: list<array<string, mixed>>}
     */
    private static function heading(string $label, array $children): array
    {
        return ['label' => $label, 'type' => MenuLinkType::Heading->value, 'children' => $children];
    }
}
