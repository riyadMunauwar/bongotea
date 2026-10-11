<?php

namespace App\Support\Settings;

use App\Models\Menu;
use Illuminate\Support\Facades\Storage;

/**
 * What is drawn only in the storefront footer: the blurb beside the store
 * name, any number of link columns (a heading and the Menu under it), the
 * Menu on the bottom line, and the Payment Banner.
 *
 * The footer is a place Menus are assigned to (ADR-0033): `placesOf()` is
 * this group's answer to "is that Menu assigned here", which `MenuPlaces`
 * asks rather than restating. The logo and the contacts are the Store's.
 *
 * The Payment Banner is a path on the public `local` disk (ADR-0011) and has
 * no default, so a fresh store never advertises a gateway it has not
 * switched on.
 *
 * @phpstan-type FooterColumn array{heading: string, menuId: ?int}
 */
class FooterSettings extends SettingsGroup
{
    /** Where the uploaded Payment Banner is stored. */
    public const IMAGE_DISK = 'local';

    public const IMAGE_DIRECTORY = 'footer';

    public string $blurb = 'চায়ের দোকান, রেস্টুরেন্ট ও ব্যবসার জন্য বাছাই করা চা। বেশি নিলে প্রতি কেজির দাম কম।';

    /**
     * @var list<FooterColumn>
     */
    public array $columns = [];

    /** The Menu on the bottom line. None leaves it with the © alone. */
    public ?int $bottomMenuId = null;

    public ?string $paymentBanner = null;

    public static function key(): string
    {
        return 'footer';
    }

    /**
     * The base coerces scalars and leaves arrays alone, so a damaged row
     * holding a string where the columns go, or a column missing its keys,
     * would throw from `load()` on every request. The columns are reduced to
     * the shape this group promises before the assignment, and a Menu id
     * naming no Menu reads as none, so nothing downstream follows it.
     *
     * @param  array<string, mixed>  $values
     */
    public function with(array $values): static
    {
        $columns = array_key_exists('columns', $values) ? self::columns($values['columns']) : null;
        $bottomMenuId = array_key_exists('bottomMenuId', $values) ? self::menuIdFrom($values['bottomMenuId']) : null;

        $named = self::namedMenuIds($columns ?? [], $bottomMenuId);
        $existing = $named === [] ? [] : Menu::query()->whereKey($named)->pluck('id')->all();
        $ifExisting = fn (?int $id): ?int => in_array($id, $existing, true) ? $id : null;

        if ($columns !== null) {
            $values['columns'] = array_map(fn (array $column): array => [...$column, 'menuId' => $ifExisting($column['menuId'])], $columns);
        }

        if (array_key_exists('bottomMenuId', $values)) {
            $values['bottomMenuId'] = $ifExisting($bottomMenuId);
        }

        return parent::with($values);
    }

    /**
     * The places in the footer this Menu is assigned to, as a Merchant
     * reads them.
     *
     * @return list<string>
     */
    public function placesOf(int $menuId): array
    {
        $places = [];

        foreach ($this->columns as $column) {
            if ($column['menuId'] === $menuId) {
                $places[] = filled($column['heading']) ? "Footer column “{$column['heading']}”" : 'Footer column';
            }
        }

        if ($this->bottomMenuId === $menuId) {
            $places[] = 'Footer bottom line';
        }

        return $places;
    }

    /**
     * Every Menu the footer draws, each once.
     *
     * @return list<int>
     */
    public function menuIds(): array
    {
        return self::namedMenuIds($this->columns, $this->bottomMenuId);
    }

    public function paymentBannerUrl(): ?string
    {
        return blank($this->paymentBanner) ? null : url(Storage::disk(self::IMAGE_DISK)->url($this->paymentBanner));
    }

    /**
     * The readable columns, as a plain list: a Repeater's uuid-keyed state
     * saves without its keys.
     *
     * @return list<FooterColumn>
     */
    private static function columns(mixed $columns): array
    {
        $kept = [];

        foreach (is_array($columns) ? $columns : [] as $column) {
            if (! is_array($column)) {
                continue;
            }

            $kept[] = [
                'heading' => is_scalar($column['heading'] ?? null) ? trim((string) $column['heading']) : '',
                'menuId' => self::menuIdFrom($column['menuId'] ?? null),
            ];
        }

        return $kept;
    }

    /**
     * @param  list<FooterColumn>  $columns
     * @return list<int>
     */
    private static function namedMenuIds(array $columns, ?int $bottomMenuId): array
    {
        return array_values(array_unique(array_filter([...array_column($columns, 'menuId'), $bottomMenuId])));
    }

    private static function menuIdFrom(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
