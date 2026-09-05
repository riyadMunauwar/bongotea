<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every Page gains a Delivery Option of its own, seeded from the single
     * global charge it was being priced by.
     *
     * One option is exactly today's behaviour — an amount added to the total
     * with no question asked — so a Merchant who never opens the Checkout tab
     * sees no change at all, which is the whole of what this backfill is for.
     *
     * Spelled out rather than read from CheckoutConfig, like every other
     * migration here: what a migration wrote on the day it ran must not change
     * meaning afterwards. Reading a Config fills in whatever it does not find,
     * so a row in this shape keeps reading correctly as the shape grows.
     *
     * Row by row rather than in one statement, because this merges into JSON a
     * Page already holds and the two databases in use spell that differently in
     * SQL. There are as many Pages as a Merchant has campaigns.
     */
    public function up(): void
    {
        $charge = (int) round(
            ((float) config('giopages.delivery.charge')) * (10 ** (int) config('giopages.currency.decimals'))
        );

        self::rewriteConfigs(fn (array $config): array => [
            ...$config,
            'delivery_options' => [['label' => 'Delivery', 'amount' => $charge]],
        ]);
    }

    /**
     * The options go; the charge they were seeded from is still in config, so
     * rolling back restores the flat figure rather than losing one.
     */
    public function down(): void
    {
        self::rewriteConfigs(function (array $config): array {
            unset($config['delivery_options']);

            return $config;
        });
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $rewrite
     */
    private static function rewriteConfigs(callable $rewrite): void
    {
        DB::table('pages')->select('id', 'checkout_config')->orderBy('id')->chunk(100, function ($pages) use ($rewrite): void {
            foreach ($pages as $page) {
                $config = json_decode((string) $page->checkout_config, true);

                DB::table('pages')
                    ->where('id', $page->id)
                    ->update(['checkout_config' => json_encode($rewrite(is_array($config) ? $config : []))]);
            }
        });
    }
};
