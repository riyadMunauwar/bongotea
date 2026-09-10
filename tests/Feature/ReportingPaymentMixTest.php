<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Page;
use App\Models\Payment;
use App\Support\Reporting\PaymentMix;
use App\Support\Reporting\PaymentMixRow;
use App\Support\Reporting\ReportingPreset;
use App\Support\Reporting\ReportingQuery;
use App\Support\Reporting\ReportingWindow;

/*
| Every window below is cut from reportingNow() — a Wednesday morning in
| Dhaka — so Last 7 days runs from Thursday the 3rd to Wednesday the 9th.
| The helpers are in tests/Pest.php, shared with the other module tests.
*/

function paymentMixOf(ReportingPreset $preset = ReportingPreset::Last7Days, ?Page $page = null): PaymentMix
{
    return app(ReportingQuery::class)->paymentMix(ReportingWindow::of($preset, reportingNow()), $page);
}

/**
 * @return list<PaymentMethod>
 */
function paymentMixMethods(PaymentMix $mix): array
{
    return array_map(fn (PaymentMixRow $row): PaymentMethod => $row->method, $mix->rows);
}

it('gives Orders and Sales per recorded Payment Method, in the enum\'s order, and only for methods recorded in the window', function () {
    orderCapturedAt('2026-09-05 12:00', 50000, ['payment_method' => PaymentMethod::SslCommerz]);
    orderCapturedAt('2026-09-05 13:00', 30000, ['payment_method' => PaymentMethod::CashOnDelivery], OrderStatus::Confirmed);
    orderCapturedAt('2026-09-06 12:00', 80000, ['payment_method' => PaymentMethod::CashOnDelivery], OrderStatus::Cancelled);
    orderCapturedAt('2026-09-06 13:00', 90000, ['payment_method' => PaymentMethod::Bkash])->delete();
    orderCapturedAt('2026-09-02 23:59', 20000, ['payment_method' => PaymentMethod::AamarPay]);

    $mix = paymentMixOf();

    expect(paymentMixMethods($mix))->toBe([PaymentMethod::CashOnDelivery, PaymentMethod::SslCommerz]);

    [$cash, $sslcommerz] = $mix->rows;

    expect($cash->orders)->toBe(2)
        ->and($cash->sales)->toBe(36000)
        ->and($cash->paid())->toBeNull()
        ->and($cash->unpaid)->toBeNull()
        ->and($sslcommerz->orders)->toBe(1)
        ->and($sslcommerz->sales)->toBe(56000);
});

it('splits each online method into Paid and Unpaid by the Order scopes: a Pending Payment is Unpaid, and a Cancelled Order that was Paid is Paid', function () {
    Payment::factory()->for(orderCapturedAt('2026-09-05 12:00', attributes: ['payment_method' => PaymentMethod::SslCommerz]))->paid()->create();
    Payment::factory()->for(orderCapturedAt('2026-09-05 13:00', attributes: ['payment_method' => PaymentMethod::SslCommerz], status: OrderStatus::Cancelled))->paid()->create();
    Payment::factory()->for(orderCapturedAt('2026-09-05 14:00', attributes: ['payment_method' => PaymentMethod::SslCommerz]))->create();
    orderCapturedAt('2026-09-05 15:00', attributes: ['payment_method' => PaymentMethod::SslCommerz]);
    orderCapturedAt('2026-09-05 16:00', attributes: ['payment_method' => PaymentMethod::Bkash]);

    [$bkash, $sslcommerz] = paymentMixOf()->rows;

    expect($sslcommerz->method)->toBe(PaymentMethod::SslCommerz)
        ->and($sslcommerz->orders)->toBe(4)
        ->and($sslcommerz->paid())->toBe(2)
        ->and($sslcommerz->unpaid)->toBe(2)
        ->and($sslcommerz->sales)->toBe(3 * 56000)
        ->and($bkash->method)->toBe(PaymentMethod::Bkash)
        ->and($bkash->orders)->toBe(1)
        ->and($bkash->paid())->toBe(0)
        ->and($bkash->unpaid)->toBe(1);
});

it('counts one Page alone when scoped to it', function () {
    $page = Page::factory()->create();
    orderCapturedAt('2026-09-05 12:00', attributes: ['page_id' => $page->id, 'payment_method' => PaymentMethod::Bkash]);
    orderCapturedAt('2026-09-05 13:00', attributes: ['payment_method' => PaymentMethod::SslCommerz]);

    $mix = paymentMixOf(page: $page);

    expect(paymentMixMethods($mix))->toBe([PaymentMethod::Bkash])
        ->and($mix->rows[0]->orders)->toBe(1);
});

it('is empty on a window with nothing captured', function () {
    orderCapturedAt('2026-09-02 12:00');

    expect(paymentMixOf()->rows)->toBe([]);
});
