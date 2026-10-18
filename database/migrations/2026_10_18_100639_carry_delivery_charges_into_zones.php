<?php

use App\Support\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Delivery Zones (ADR-0039) replace the one national table of Delivery
     * Bands and the flat retail charge. A store already charging keeps
     * charging exactly what it did: its bands become the bulk "everywhere
     * else" zone, and its retail charge and threshold the retail one, as a
     * single 1 kg-and-over band. New zones are the Merchant's to add.
     *
     * A store that never saved its Delivery Charges was charging the old
     * defaults, so those are carried over for it too — but only where there
     * is a catalogue to have been selling from; a fresh installation opens on
     * the new defaults instead.
     */
    public function up(): void
    {
        $stored = DB::table('settings')->where('key', 'pricing')->value('value');
        $values = is_string($stored) ? (json_decode($stored, true) ?: []) : [];

        if (array_key_exists('bulkZones', $values)) {
            return;
        }

        if ($stored === null && ! DB::table('products')->exists()) {
            return;
        }

        $bands = $values['deliveryBands'] ?? [
            ['min' => 1, 'max' => 5, 'fee' => Money::toMinorUnits(120)],
            ['min' => 6, 'max' => 10, 'fee' => Money::toMinorUnits(150)],
            ['min' => 11, 'max' => 20, 'fee' => Money::toMinorUnits(200)],
            ['min' => 21, 'max' => 50, 'fee' => Money::toMinorUnits(250)],
            ['min' => 51, 'max' => null, 'fee' => 0],
        ];

        $retailCharge = $values['retailDeliveryCharge'] ?? Money::toMinorUnits(60);
        $retailFreeFrom = array_key_exists('retailFreeDeliveryFrom', $values) ? $values['retailFreeDeliveryFrom'] : Money::toMinorUnits(1000);

        unset($values['deliveryBands'], $values['retailDeliveryCharge'], $values['retailFreeDeliveryFrom']);

        $values['bulkZones'] = [
            ['name' => 'Everywhere', 'districts' => [], 'everywhereElse' => true, 'bands' => $bands],
        ];

        $values['retailZones'] = [
            ['name' => 'Everywhere', 'districts' => [], 'everywhereElse' => true, 'bands' => [['min' => 1, 'max' => null, 'fee' => $retailCharge]], 'freeDeliveryFrom' => $retailFreeFrom],
        ];

        DB::table('settings')->updateOrInsert(['key' => 'pricing'], ['value' => json_encode($values), 'updated_at' => now(), ...($stored === null ? ['created_at' => now()] : [])]);
    }

    /**
     * Back to the one national table and the flat retail charge, read off the
     * "everywhere else" zones: other zones have nowhere to go and are dropped.
     */
    public function down(): void
    {
        $stored = DB::table('settings')->where('key', 'pricing')->value('value');
        $values = is_string($stored) ? (json_decode($stored, true) ?: []) : [];

        if (! array_key_exists('bulkZones', $values)) {
            return;
        }

        $everywhereElse = fn (array $zones): ?array => collect($zones)->firstWhere('everywhereElse', true);
        $bulk = $everywhereElse($values['bulkZones']);
        $retail = $everywhereElse($values['retailZones'] ?? []);

        unset($values['bulkZones'], $values['retailZones']);

        if ($bulk !== null) {
            $values['deliveryBands'] = $bulk['bands'];
        }

        if ($retail !== null) {
            $values['retailDeliveryCharge'] = $retail['bands'][0]['fee'];
            $values['retailFreeDeliveryFrom'] = $retail['freeDeliveryFrom'] ?? null;
        }

        DB::table('settings')->where('key', 'pricing')->update(['value' => json_encode($values), 'updated_at' => now()]);
    }
};
