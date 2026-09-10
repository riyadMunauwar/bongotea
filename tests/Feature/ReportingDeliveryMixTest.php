<?php

use App\Enums\OrderStatus;
use App\Models\Page;
use App\Support\Reporting\DeliveryMix;
use App\Support\Reporting\DeliveryMixRow;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;

/*
| Every window below is cut from reportingNow() — a Wednesday morning in
| Dhaka — so Last 7 days runs from Thursday the 3rd to Wednesday the 9th.
| The helpers are in tests/Pest.php, shared with the other module tests.
*/

function deliveryMixOf(ReportingPreset $preset = ReportingPreset::Last7Days, ?Page $page = null): DeliveryMix
{
    return app(ReportingQuery::class)->deliveryMix(ReportingWindow::of($preset, reportingNow()), $page);
}

/**
 * @return list<array{string|null, int, int}>
 */
function deliveryMixRows(DeliveryMix $mix): array
{
    return array_map(
        fn (DeliveryMixRow $row): array => [$row->label, $row->orders, $row->deliveryCharge],
        $mix->rows,
    );
}

it('groups by the recorded Delivery Option label, with the Orders and the Delivery Charge each collected, most Orders first', function () {
    orderCapturedAt('2026-09-05 12:00', attributes: ['delivery_label' => 'Inside Dhaka', 'delivery_charge' => 6000]);
    orderCapturedAt('2026-09-05 13:00', attributes: ['delivery_label' => 'Inside Dhaka', 'delivery_charge' => 6000], status: OrderStatus::Confirmed);
    orderCapturedAt('2026-09-06 12:00', attributes: ['delivery_label' => 'Outside Dhaka', 'delivery_charge' => 12000]);

    expect(deliveryMixRows(deliveryMixOf()))->toBe([
        ['Inside Dhaka', 2, 12000],
        ['Outside Dhaka', 1, 12000],
    ]);
});

it('reads only the Orders that count: not Cancelled, not deleted, and inside the window', function () {
    orderCapturedAt('2026-09-05 12:00', attributes: ['delivery_label' => 'Inside Dhaka', 'delivery_charge' => 6000]);
    orderCapturedAt('2026-09-05 13:00', attributes: ['delivery_label' => 'Inside Dhaka', 'delivery_charge' => 6000], status: OrderStatus::Cancelled);
    orderCapturedAt('2026-09-02 23:59', attributes: ['delivery_label' => 'Inside Dhaka', 'delivery_charge' => 6000]);
    orderCapturedAt('2026-09-06 12:00', attributes: ['delivery_label' => 'Inside Dhaka', 'delivery_charge' => 6000])->delete();

    expect(deliveryMixRows(deliveryMixOf()))->toBe([['Inside Dhaka', 1, 6000]]);
});

it('gathers the Orders that recorded no label into one row that names none', function () {
    orderCapturedAt('2026-09-05 12:00', attributes: ['delivery_label' => null, 'delivery_charge' => 6000]);
    orderCapturedAt('2026-09-05 13:00', attributes: ['delivery_label' => '', 'delivery_charge' => 6000]);
    orderCapturedAt('2026-09-06 12:00', attributes: ['delivery_label' => 'Inside Dhaka', 'delivery_charge' => 6000]);

    expect(deliveryMixRows(deliveryMixOf()))->toBe([
        [null, 2, 12000],
        ['Inside Dhaka', 1, 6000],
    ]);
});

it('names the five Districts the most Orders were bound for, leaving out the Orders that recorded none', function () {
    $bound = function (string $district, int $orders): void {
        foreach (range(1, $orders) as $order) {
            orderCapturedAt('2026-09-05 12:00', attributes: ['customer_district' => $district]);
        }
    };

    $bound('Dhaka', 6);
    $bound('Chattogram', 5);
    $bound('Khulna', 4);
    $bound('Sylhet', 3);
    $bound('Rajshahi', 2);
    $bound('Barishal', 1);
    $bound('', 7);

    orderCapturedAt('2026-09-05 12:00', attributes: ['customer_district' => null]);
    orderCapturedAt('2026-09-05 13:00', attributes: ['customer_district' => 'Dhaka'], status: OrderStatus::Cancelled);

    expect(deliveryMixOf()->districts)->toBe([
        'Dhaka' => 6,
        'Chattogram' => 5,
        'Khulna' => 4,
        'Sylhet' => 3,
        'Rajshahi' => 2,
    ]);
});

it('counts one Page alone when scoped to it', function () {
    $page = Page::factory()->create();
    orderCapturedAt('2026-09-05 12:00', attributes: ['page_id' => $page->id, 'delivery_label' => 'Inside Dhaka', 'customer_district' => 'Dhaka']);
    orderCapturedAt('2026-09-05 13:00', attributes: ['delivery_label' => 'Outside Dhaka', 'customer_district' => 'Sylhet']);

    $mix = deliveryMixOf(page: $page);

    expect(deliveryMixRows($mix))->toBe([['Inside Dhaka', 1, 6000]])
        ->and($mix->districts)->toBe(['Dhaka' => 1]);
});

it('is empty on a window with nothing captured', function () {
    orderCapturedAt('2026-09-02 12:00');

    $mix = deliveryMixOf();

    expect($mix->rows)->toBe([])
        ->and($mix->districts)->toBe([]);
});
