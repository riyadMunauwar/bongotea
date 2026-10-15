<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Block kinds that drew the two Carts before a Block named its Cart,
     * by the kind of Cart each drew, with the starting Cart made for it where
     * an installation does not have one yet. A copy as they shipped, not a
     * reference to app code, so this migration reads the same whatever later
     * becomes of them.
     *
     * @var array<string, array{kind: string, name: string, sort_order: int}>
     */
    private const DRAWN_BY = [
        'bulk_order_form' => ['kind' => 'bulk', 'name' => 'পাইকারি অর্ডার করুন', 'sort_order' => 1],
        'packet_basket' => ['kind' => 'retail', 'name' => 'খুচরা অর্ডার করুন', 'sort_order' => 0],
    ];

    /**
     * Every Block that drew "the" bulk Cart or "the" packet basket now names
     * the starting Cart of its kind, so a Customer's Contents and the Page
     * they were gathered on stay together. A Cart stands on one Page at most,
     * so only the first such Block of each kind is given it; any other is left
     * naming none, and draws nothing until the Merchant points it at a Cart.
     */
    public function up(): void
    {
        $placed = [];

        foreach (DB::table('pages')->orderBy('id')->get(['id', 'blocks']) as $page) {
            $blocks = json_decode((string) $page->blocks, true);

            if (! is_array($blocks)) {
                continue;
            }

            $changed = false;

            foreach ($blocks as $index => $block) {
                $drawn = self::DRAWN_BY[$block['type'] ?? ''] ?? null;

                if ($drawn === null || isset($block['data']['cart_id']) || isset($placed[$drawn['kind']])) {
                    continue;
                }

                $placed[$drawn['kind']] = true;
                $blocks[$index]['data'] = [...($block['data'] ?? []), 'cart_id' => self::startingCart($drawn)];
                $changed = true;
            }

            if ($changed) {
                DB::table('pages')->where('id', $page->id)->update(['blocks' => json_encode($blocks)]);
            }
        }
    }

    /**
     * @param  array{kind: string, name: string, sort_order: int}  $cart
     */
    private static function startingCart(array $cart): int
    {
        return DB::table('carts')->where('starting_kind', $cart['kind'])->value('id')
            ?? DB::table('carts')->insertGetId([
                ...$cart,
                'starting_kind' => $cart['kind'],
                'is_switched_on' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * Left as it is: a Block naming its Cart reads the same to code that no
     * longer asks.
     */
    public function down(): void {}
};
