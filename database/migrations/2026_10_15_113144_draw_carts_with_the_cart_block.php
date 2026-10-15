<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The Block kinds the one Cart Block replaces, as they were stored, and
     * the settings each kept. A copy, not a reference to app code, so this
     * migration reads the same whatever later becomes of them.
     *
     * @var array<string, list<string>>
     */
    private const REPLACED = [
        'bulk_order_form' => ['cart_id', 'heading', 'text', 'quick_chips', 'show_tier_list', 'anchor', 'hide_on_mobile', 'hide_on_desktop'],
        'packet_basket' => ['cart_id', 'heading', 'text', 'anchor', 'hide_on_mobile', 'hide_on_desktop'],
    ];

    /**
     * Every Bulk order form and Packet basket Block becomes a Cart Block
     * drawing the same Cart under the same wording and switches, so each Page
     * renders as it did.
     */
    public function up(): void
    {
        foreach (DB::table('pages')->orderBy('id')->get(['id', 'blocks']) as $page) {
            $blocks = json_decode((string) $page->blocks, true);

            if (! is_array($blocks)) {
                continue;
            }

            $changed = false;

            foreach ($blocks as $index => $block) {
                $kept = self::REPLACED[$block['type'] ?? ''] ?? null;

                if ($kept === null) {
                    continue;
                }

                $blocks[$index] = [
                    ...$block,
                    'type' => 'cart',
                    'data' => array_intersect_key($block['data'] ?? [], array_flip($kept)),
                ];
                $changed = true;
            }

            if ($changed) {
                DB::table('pages')->where('id', $page->id)->update(['blocks' => json_encode($blocks)]);
            }
        }
    }

    /**
     * Left as it is: the Blocks it replaced no longer exist to go back to.
     */
    public function down(): void {}
};
