<?php

use App\Enums\Courier;
use App\Enums\ShipmentStatus;
use App\Models\Order;
use App\Models\Shipment;
use App\Support\Reporting\CourierPerformance;
use App\Support\Reporting\CourierPerformanceRow;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;
use App\Support\Settings\GeneralSettings;
use Carbon\CarbonImmutable;

/*
| Every window below is cut from reportingNow() — a Wednesday morning in
| Dhaka — so Last 7 days runs from Thursday the 3rd to Wednesday the 9th.
| A parcel is placed by the instant its Courier accepted it, which is the
| only instant deciding whether it falls inside.
*/

function courierPerformanceOf(ReportingPreset $preset = ReportingPreset::Last7Days): CourierPerformance
{
    return app(ReportingQuery::class)->courierPerformance(ReportingWindow::of($preset, reportingNow()));
}

function performanceOf(Courier $courier, ReportingPreset $preset = ReportingPreset::Last7Days): CourierPerformanceRow
{
    return collect(courierPerformanceOf($preset)->rows)->firstOrFail(
        fn (CourierPerformanceRow $row): bool => $row->courier === $courier,
    );
}

/**
 * A parcel one Courier accepted at an instant and has since said whatever it
 * says, the outcome dated the way the row records it: a terminal attempt is
 * never polled again, so the last write to it is the outcome being written.
 *
 * @param  array<string, mixed>  $attributes
 */
function parcelBookedAt(string $booked, Courier $courier = Courier::Steadfast, ?string $settled = null, array $attributes = []): Shipment
{
    $bookedAt = CarbonImmutable::parse($booked, 'Asia/Dhaka')->utc();

    return Shipment::factory()->for(Order::factory())->create(array_merge([
        'courier' => $courier,
        'booked_at' => $bookedAt,
        'created_at' => $bookedAt,
        'updated_at' => $settled === null ? $bookedAt : CarbonImmutable::parse($settled, 'Asia/Dhaka')->utc(),
    ], $attributes));
}

it('counts a Courier’s parcels, arrivals and returns inside the window and not outside it', function () {
    parcelBookedAt('2026-09-05 10:00', settled: '2026-09-06 10:00', attributes: ['status' => ShipmentStatus::Delivered]);
    parcelBookedAt('2026-09-06 10:00', settled: '2026-09-08 10:00', attributes: ['status' => ShipmentStatus::Returned]);
    parcelBookedAt('2026-09-07 10:00', attributes: ['status' => ShipmentStatus::InTransit]);

    /** A minute before the window opens, and nothing of it is counted. */
    parcelBookedAt('2026-09-02 23:59', settled: '2026-09-03 10:00', attributes: ['status' => ShipmentStatus::Delivered]);

    $steadfast = performanceOf(Courier::Steadfast);

    expect($steadfast->sent)->toBe(3)
        ->and($steadfast->delivered)->toBe(1)
        ->and($steadfast->returned)->toBe(1);
});

it('places a parcel by the morning its Courier accepted it, never by the day its Order was captured', function () {
    /** An Order taken long before the window, handed to a Courier inside it. */
    $order = Order::factory()->create(['created_at' => CarbonImmutable::parse('2026-08-01 10:00', 'Asia/Dhaka')->utc()]);

    Shipment::factory()->for($order)->create([
        'courier' => Courier::Pathao,
        'booked_at' => CarbonImmutable::parse('2026-09-05 10:00', 'Asia/Dhaka')->utc(),
    ]);

    expect(performanceOf(Courier::Pathao)->sent)->toBe(1);
});

it('counts a parcel nobody has handed over, and one a Courier would not take, as sent by nobody', function () {
    /** Written by a batch and not dispatched yet: Active, and never offered to anybody. */
    Shipment::factory()->for(Order::factory())->create([
        'courier' => Courier::Steadfast,
        'status' => ShipmentStatus::Written,
        'raw_status' => null,
        'consignment_reference' => null,
        'booked_at' => null,
    ]);

    Shipment::factory()->for(Order::factory())->refused()->create(['courier' => Courier::Steadfast]);

    $steadfast = performanceOf(Courier::Steadfast);

    expect($steadfast->sent)->toBe(0)
        ->and($steadfast->deliveryRate())->toBeNull();
});

it('counts a parcel a User wrote off among the ones that went out, and among neither outcome', function () {
    parcelBookedAt('2026-09-05 10:00', settled: '2026-09-06 10:00', attributes: ['status' => ShipmentStatus::Delivered]);
    parcelBookedAt('2026-09-06 10:00', attributes: ['status' => ShipmentStatus::OnHold, 'abandoned_at' => now()]);

    $steadfast = performanceOf(Courier::Steadfast);

    /** Two parcels cost money; one arrived, one is dead, and neither rate pretends otherwise. */
    expect($steadfast->sent)->toBe(2)
        ->and($steadfast->delivered)->toBe(1)
        ->and($steadfast->returned)->toBe(0)
        ->and($steadfast->deliveryRate())->toBe(0.5)
        ->and($steadfast->returnRate())->toBe(0.0);
});

it('reads a partly delivered parcel as one that got to the door', function () {
    parcelBookedAt('2026-09-05 10:00', settled: '2026-09-06 10:00', attributes: ['status' => ShipmentStatus::PartlyDelivered]);

    $steadfast = performanceOf(Courier::Steadfast);

    expect($steadfast->delivered)->toBe(1)
        ->and($steadfast->deliveryRate())->toBe(1.0);
});

it('works the rates out over a mixed set of outcomes, one Courier at a time', function () {
    /** Steadfast: four out, three arrived, one came back. */
    foreach (['2026-09-04 10:00', '2026-09-05 10:00', '2026-09-06 10:00'] as $morning) {
        parcelBookedAt($morning, settled: '2026-09-08 10:00', attributes: ['status' => ShipmentStatus::Delivered]);
    }
    parcelBookedAt('2026-09-07 10:00', settled: '2026-09-08 10:00', attributes: ['status' => ShipmentStatus::Returned]);

    /** Pathao: four out, one arrived, two came back, one still moving. */
    parcelBookedAt('2026-09-04 10:00', Courier::Pathao, settled: '2026-09-05 10:00', attributes: ['status' => ShipmentStatus::Delivered]);
    parcelBookedAt('2026-09-05 10:00', Courier::Pathao, settled: '2026-09-06 10:00', attributes: ['status' => ShipmentStatus::Returned]);
    parcelBookedAt('2026-09-06 10:00', Courier::Pathao, settled: '2026-09-07 10:00', attributes: ['status' => ShipmentStatus::Returned]);
    parcelBookedAt('2026-09-07 10:00', Courier::Pathao, attributes: ['status' => ShipmentStatus::InTransit]);

    expect(performanceOf(Courier::Steadfast)->deliveryRate())->toBe(0.75)
        ->and(performanceOf(Courier::Steadfast)->returnRate())->toBe(0.25)
        ->and(performanceOf(Courier::Pathao)->deliveryRate())->toBe(0.25)
        ->and(performanceOf(Courier::Pathao)->returnRate())->toBe(0.5);
});

it('averages the days to delivery over the parcels that arrived, ignoring the ones that never did', function () {
    parcelBookedAt('2026-09-04 10:00', settled: '2026-09-05 10:00', attributes: ['status' => ShipmentStatus::Delivered]);
    parcelBookedAt('2026-09-04 10:00', settled: '2026-09-07 10:00', attributes: ['status' => ShipmentStatus::Delivered]);

    /** Days on the road and still moving, and a return that took four: neither is a delivery time. */
    parcelBookedAt('2026-09-04 10:00', attributes: ['status' => ShipmentStatus::InTransit]);
    parcelBookedAt('2026-09-04 10:00', settled: '2026-09-08 10:00', attributes: ['status' => ShipmentStatus::Returned]);

    expect(performanceOf(Courier::Steadfast)->averageDaysToDelivery)->toBe(2.0);
});

it('says nothing at all about the days of a Courier nothing has arrived from', function () {
    parcelBookedAt('2026-09-05 10:00', attributes: ['status' => ShipmentStatus::InTransit]);

    $steadfast = performanceOf(Courier::Steadfast);

    expect($steadfast->averageDaysToDelivery)->toBeNull()
        ->and($steadfast->sent)->toBe(1);
});

it('adds up only the charges a Courier has actually named, and says nothing where it has named none', function () {
    /** Steadfast takes a parcel quoting nothing and names the charge later. */
    parcelBookedAt('2026-09-05 10:00', attributes: ['courier_charge' => null]);
    parcelBookedAt('2026-09-06 10:00', attributes: ['courier_charge' => null]);

    parcelBookedAt('2026-09-05 10:00', Courier::Pathao, attributes: ['courier_charge' => 6000]);
    parcelBookedAt('2026-09-06 10:00', Courier::Pathao, attributes: ['courier_charge' => 7000]);
    parcelBookedAt('2026-09-07 10:00', Courier::Pathao, attributes: ['courier_charge' => null]);

    expect(performanceOf(Courier::Steadfast)->charges)->toBeNull()
        ->and(performanceOf(Courier::Pathao)->charges)->toBe(13000);
});

it('cuts the window’s days in the Merchant’s timezone, not the server’s', function () {
    /** Half past midnight on Thursday the 3rd in Dhaka is still Wednesday evening in UTC. */
    parcelBookedAt('2026-09-03 00:30');

    /** An hour earlier is the day before the window opens, wherever it is read. */
    parcelBookedAt('2026-09-02 23:30');

    expect(performanceOf(Courier::Steadfast)->sent)->toBe(1);

    /**
     * Read in UTC the window opens six hours later, so the parcel Dhaka
     * counts as the first morning's is the one UTC leaves out.
     */
    app(GeneralSettings::class)->with(['timezone' => 'UTC'])->save();

    expect(performanceOf(Courier::Steadfast)->sent)->toBe(0);
});

it('names every Courier the registry holds, in its order, and reads an empty window as zeros', function () {
    $performance = courierPerformanceOf();

    expect(array_map(fn (CourierPerformanceRow $row): Courier => $row->courier, $performance->rows))
        ->toBe(Courier::cases());

    foreach ($performance->rows as $row) {
        expect($row->sent)->toBe(0)
            ->and($row->delivered)->toBe(0)
            ->and($row->returned)->toBe(0)
            /** A count is nought because nothing was carried; a rate and a charge are unknown, which is not the same thing. */
            ->and($row->charges)->toBeNull()
            ->and($row->averageDaysToDelivery)->toBeNull()
            ->and($row->deliveryRate())->toBeNull()
            ->and($row->returnRate())->toBeNull();
    }
});

it('never blends one Courier’s parcels into another’s', function () {
    parcelBookedAt('2026-09-05 10:00', Courier::Steadfast, settled: '2026-09-06 10:00', attributes: ['status' => ShipmentStatus::Delivered]);
    parcelBookedAt('2026-09-05 10:00', Courier::RedEx, settled: '2026-09-06 10:00', attributes: ['status' => ShipmentStatus::Returned]);

    expect(performanceOf(Courier::Steadfast)->delivered)->toBe(1)
        ->and(performanceOf(Courier::Steadfast)->returned)->toBe(0)
        ->and(performanceOf(Courier::RedEx)->delivered)->toBe(0)
        ->and(performanceOf(Courier::RedEx)->returned)->toBe(1)
        ->and(performanceOf(Courier::Pathao)->sent)->toBe(0);
});
