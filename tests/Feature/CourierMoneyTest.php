<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentState;
use App\Enums\ShipmentStatus;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\CourierMoneyTable;
use App\Filament\Widgets\DeliveryMixTable;
use App\Filament\Widgets\NeedsAttentionTable;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Couriers\CourierMoney;
use App\Support\Couriers\CourierMoneyRow;
use App\Support\Money;
use App\Support\Settings\CourierSettings;
use Livewire\Livewire;
use Tests\Fixtures\Couriers\CourierScript;

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

/*
| Two figures about one Courier, and the whole of what this widget is for is
| that they stay two. One is what a Courier says about its own account and
| only one of the three says anything at all; the other is counted here from
| the Merchant's own Orders and every Courier has it alike. Which Courier is
| which is the script's to declare — no test below names one.
*/

/*
|--------------------------------------------------------------------------
| The balance a Courier reports
|--------------------------------------------------------------------------
*/

it('asks a courier that reports a balance what it is holding, and draws what it said', function (CourierScript $script) {
    $script->makeUsable();
    $script->fakeBalance();

    Livewire::test(CourierMoneyTable::class)
        ->assertSee('Courier money')
        ->assertSee($script->courier()->getLabel())
        ->assertSee('Balance (courier-reported)')
        ->assertSee(Money::format((int) $script->balanceReported()));

    $script->assertBalanceAsked(1);
})->with('balance-reporting couriers');

it('holds the figure it read rather than asking the courier again for every render', function (CourierScript $script) {
    $script->makeUsable();
    $script->fakeBalance();

    Livewire::test(CourierMoneyTable::class)->assertSee(Money::format((int) $script->balanceReported()));
    Livewire::test(CourierMoneyTable::class)->assertSee(Money::format((int) $script->balanceReported()));

    $script->assertBalanceAsked(1);
})->with('balance-reporting couriers');

it('never asks a courier that publishes no balance, and says so where a figure would be read', function (CourierScript $script) {
    $script->makeUsable();

    Livewire::test(CourierMoneyTable::class)
        ->assertSee($script->courier()->getLabel())
        ->assertSee('Not reported')
        ->assertSee($script->courier()->getLabel().' publishes no balance.');

    /** Nowhere to ask, so nothing went out anywhere: the capability is read before any call is made. */
    $script->assertBalanceAsked(0);
})->with('balance-silent couriers');

it('says a balance it could not fetch is unavailable and never reads it as zero', function (CourierScript $script) {
    $script->makeUsable();
    $script->fakeOutage();

    $row = balanceRowFor($script);

    expect($row->balance->isReported())->toBeFalse()
        ->and($row->balance->amount)->toBeNull()
        ->and($row->balance->isUnavailable())->toBeTrue()
        ->and($row->balance->error)->toContain($script->courier()->getLabel());

    Livewire::test(CourierMoneyTable::class)->assertSee('Unavailable');
})->with('balance-reporting couriers');

it('leaves the figure it last read standing when a later read fails', function (CourierScript $script) {
    $script->makeUsable();
    $script->fakeBalance();

    Livewire::test(CourierMoneyTable::class)->assertSee(Money::format((int) $script->balanceReported()));

    /** Past the interval the figure is left alone for, so the next render asks again — and this time the answer settles nothing. */
    $this->travel(10)->minutes();
    answeringAfresh();
    $script->fakeBalanceUnavailable();

    Livewire::test(CourierMoneyTable::class)
        ->assertSee(Money::format((int) $script->balanceReported()))
        ->assertDontSee('Unavailable');

    /** It was asked again — the interval is what decides that, and what a failed answer leaves behind is the figure. */
    $script->assertBalanceAsked(1);
})->with('balance-reporting couriers');

it('stops standing on a figure once it is older than a day, however often it has been re-read since', function (CourierScript $script) {
    $script->makeUsable();
    $script->fakeBalance();

    Livewire::test(CourierMoneyTable::class)->assertSee(Money::format((int) $script->balanceReported()));

    answeringAfresh();
    $script->fakeBalanceUnavailable();

    /** A Merchant opening the screen through the outage: every read fails, and the figure stands for its day. */
    $this->travel(18)->hours();

    Livewire::test(CourierMoneyTable::class)->assertSee(Money::format((int) $script->balanceReported()));

    /**
     * Past that day, yesterday's money is no longer an answer — and the day
     * is measured from when the Courier gave the figure, so re-reading it all
     * night does not renew it.
     */
    $this->travel(12)->hours();

    Livewire::test(CourierMoneyTable::class)
        ->assertSee('Unavailable')
        ->assertDontSee(Money::format((int) $script->balanceReported()));
})->with('balance-reporting couriers');

it('does not show one account\'s money under another account\'s credentials', function (CourierScript $script) {
    $script->makeUsable();
    $script->fakeBalance();

    Livewire::test(CourierMoneyTable::class)->assertSee(Money::format((int) $script->balanceReported()));

    /** A second account pasted into Settings is a different question, and the first one's figure is no answer to it. */
    answeringAfresh();
    $script->makeUsableWithOtherCredentials();
    $script->fakeBalanceUnavailable();

    Livewire::test(CourierMoneyTable::class)
        ->assertSee('Unavailable')
        ->assertDontSee(Money::format((int) $script->balanceReported()));
})->with('balance-reporting couriers');

it('leaves out a courier nobody has set up and that is carrying nothing', function (CourierScript $script) {
    expect(app(CourierMoney::class)->rows())->toBe([]);

    Livewire::test(CourierMoneyTable::class)
        ->assertDontSee($script->courier()->getLabel())
        /** Not a Merchant whose money has gone — one who has not started. */
        ->assertSee('No Couriers set up');

    /** Nothing is asked of a Courier that has not been supplied, whatever it reports. */
    $script->assertBalanceAsked(0);
})->with('courier scripts');

it('goes on listing a courier switched off while its parcels are still out, and says why there is no figure', function (CourierScript $script) {
    /** Set up, handed a parcel, and switched off afterwards — the credentials are left exactly where they were. */
    parcelInFlight($script, ['collect_amount' => 105000]);

    switchOff($script);

    $row = balanceRowFor($script);

    expect($row->balance->isReported())->toBeFalse()
        /** The money is the Merchant's own Orders and is entirely real, whatever Settings now says. */
        ->and($row->codInFlight)->toBe(105000)

        /**
         * Why there is no figure is this Courier's own asymmetry. One that
         * publishes a balance says it cannot be asked for it; one that
         * publishes none says nothing about Settings at all, because the
         * capability is read first and a Courier with no such endpoint is
         * never put this installation's question.
         */
        ->and($row->balance->error)->toBe($script->supportsBalance() ? $script->courier()->refusal() : null);

    Livewire::test(CourierMoneyTable::class)
        ->assertSee($script->courier()->getLabel())
        ->assertSee(Money::format(105000));

    /** Still nothing asked of a Courier this installation cannot speak to. */
    $script->assertBalanceAsked(0);
})->with('courier scripts');

it('draws a courier that is set up and carrying nothing, because a zero in flight is the truth', function (CourierScript $script) {
    $script->makeUsable();
    $script->fakeBalance();

    expect(balanceRowFor($script)->codInFlight)->toBe(0);

    Livewire::test(CourierMoneyTable::class)
        ->assertSee($script->courier()->getLabel())
        ->assertDontSee('No Couriers set up');
})->with('courier scripts');

/*
|--------------------------------------------------------------------------
| The money still out with the riders
|--------------------------------------------------------------------------
*/

it('counts COD in Flight from that courier\'s Active Shipments alone, and nothing for an order already paid online', function (CourierScript $script) {
    $script->fakeBalance();

    parcelInFlight($script, ['collect_amount' => 105000]);
    parcelInFlight($script, ['collect_amount' => 30000]);

    /** Arrived, written off, refused when it was offered: none of it is money a Courier is still carrying. */
    parcelInFlight($script, ['collect_amount' => 500000, 'status' => ShipmentStatus::Delivered, 'raw_status' => $script->returnedWording()]);
    parcelInFlight($script, ['collect_amount' => 700000, 'abandoned_at' => now()]);
    parcelInFlight($script, ['collect_amount' => 900000, 'refused_at' => now(), 'refusal_reason' => $script->refusalWording()]);

    /** Paid on the Page, so the rider collects nothing at the door and it is not money in flight. */
    $paid = orderReadyToSend($script, ['payment_method' => PaymentMethod::SslCommerz]);
    Payment::factory()->for($paid)->create(['state' => PaymentState::Paid, 'amount' => $paid->total]);
    $onlineParcel = Shipment::factory()->for($paid->refresh())->create(['courier' => $script->courier()]);

    expect($onlineParcel->collect_amount)->toBe(0)
        ->and(balanceRowFor($script)->codInFlight)->toBe(135000);

    Livewire::test(CourierMoneyTable::class)
        ->assertSee('COD in Flight')
        ->assertSee(Money::format(135000))
        ->assertDontSee(Money::format(2235000));
})->with('courier scripts');

it('draws the balance and COD in Flight as two figures and never as one number', function (CourierScript $script) {
    $script->fakeBalance();

    parcelInFlight($script, ['collect_amount' => 105000]);

    $balance = (int) $script->balanceReported();

    Livewire::test(CourierMoneyTable::class)
        ->assertSee(Money::format($balance))
        ->assertSee(Money::format(105000))
        ->assertDontSee(Money::format($balance + 105000));
})->with('balance-reporting couriers');

/*
|--------------------------------------------------------------------------
| Where it sits
|--------------------------------------------------------------------------
*/

it('sits full width on the dashboard, above the Needs Attention list', function () {
    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire(CourierMoneyTable::class);

    $widgets = array_values(app(Dashboard::class)->getWidgets());

    expect(array_search(DeliveryMixTable::class, $widgets, true))
        ->toBeLessThan(array_search(CourierMoneyTable::class, $widgets, true))
        ->and(array_search(CourierMoneyTable::class, $widgets, true))
        ->toBeLessThan(array_search(NeedsAttentionTable::class, $widgets, true))
        ->and(app(CourierMoneyTable::class)->getColumnSpan())->toBe('full');
});

/**
 * Stop using this Courier, leaving everything pasted about it where it is —
 * which is what a Merchant actually does, and is why being switched off can
 * never be read as having no parcels out.
 *
 * The property is asked of the group rather than spelled here: its name is
 * the Courier's own in camel case plus `Enabled`, and a second spelling of
 * that rule in a test is a test that goes on passing after the rule moves.
 */
function switchOff(CourierScript $script): void
{
    $enabled = CourierSettings::property($script->courier(), 'Enabled');

    app(CourierSettings::class)->with([$enabled => false])->save();
}

/**
 * This Courier's own row of the module the widget draws, so a test can assert
 * on the two figures themselves rather than on how they were worded.
 */
function balanceRowFor(CourierScript $script): CourierMoneyRow
{
    $row = collect(app(CourierMoney::class)->rows())
        ->first(fn (CourierMoneyRow $row): bool => $row->courier === $script->courier());

    expect($row)->not->toBeNull("No row was drawn for {$script->courier()->getLabel()}.");

    return $row;
}
