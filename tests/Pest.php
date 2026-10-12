<?php

use App\Enums\AdjustmentCause;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ShipmentStatus;
use App\Enums\StockMovementReason;
use App\Enums\TrackingMoment;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\StockMovement;
use App\Models\TrackingContext;
use App\Support\Customers\CustomerGuard;
use App\Support\Inventory\StockLedger;
use App\Support\Orders\OrderCapture;
use App\Support\Sms\SendText;
use App\Support\Tracking\MomentRecorder;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Pest\Expectation;
use Tests\Fixtures\Couriers\CourierScript;
use Tests\Fixtures\Payments\GatewayScript;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| No test reaches a gateway
|--------------------------------------------------------------------------
|
| Every gateway is spoken to through the framework's HTTP client, and that
| is the one seam the tests fake. A test that forgets to is refused rather
| than left to reach a real sandbox, wait out a timeout, and pass on the
| Failed Payment the outage produced.
|
*/

pest()->beforeEach(function (): void {
    Http::preventStrayRequests();
})->in('Feature');

/*
|--------------------------------------------------------------------------
| Livewire state isolation
|--------------------------------------------------------------------------
|
| Not here. Livewire's "a component rendered this request" flag is static and
| outlives the application rebuild between tests, so a per-test hook would
| still leave two requests inside one test sharing it. `Tests\TestCase::call()`
| flushes it per request instead, which is where the reasoning is written down.
|
*/

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/**
 * Follow a URL the way a webserver does — to a file under the document root.
 *
 * A Product image is served straight off disk with no framework boot
 * (ADR-0011), so nothing in the application answers its address. All that
 * stands between a Customer and a missing picture is a document-root link
 * pointing at the disk the file is on. This walks that route in order: the
 * URL's path, the link that answers it, the disk that link exposes, the file.
 *
 * The links are read from configuration rather than from the filesystem, so
 * this proves the same thing on a checkout where nobody has run
 * `storage:link` — the failure it exists to catch is a URL and a disk that
 * were never wired to each other, not a missing symlink on one machine.
 */
expect()->extend('toBeServedFromTheDocumentRoot', function (): Expectation {
    $path = '/'.ltrim((string) parse_url($this->value, PHP_URL_PATH), '/');

    /** @var Collection<string, string> $links Link target, keyed by the address it answers. */
    $links = collect(config('filesystems.links'))
        ->mapWithKeys(fn (string $target, string $link): array => [
            str_replace('\\', '/', Str::after($link, public_path())) => $target,
        ]);

    $address = $links->keys()->first(fn (string $address): bool => Str::startsWith($path, $address.'/'));

    expect($address)->not->toBeNull("No document-root link answers [{$path}].");

    $disk = collect(config('filesystems.disks'))
        ->search(fn (array $configuration): bool => ($configuration['root'] ?? null) === $links[$address]);

    expect($disk)->not->toBeFalse("[{$address}] links to [{$links[$address]}], which is no disk's root.");

    expect(Storage::disk($disk)->exists(Str::after($path, $address.'/')))
        ->toBeTrue("[{$path}] resolves to nothing on the [{$disk}] disk.");

    return $this;
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * An Order as Capture leaves it: one kilogram of a Product, delivered to
 * Dhaka and paid on delivery, unless a test says otherwise.
 *
 * Captured through `OrderCapture` itself rather than made by a factory, so
 * what a test reads off it is what a real Capture writes.
 *
 * @param  array<string, mixed>  $details  overrides for the delivery details Capture takes
 * @param  list<array{product: Product, kilograms: int}>|null  $lines  the Cart; one Product at 1,050.00 a kilogram where none is given
 */
function orderAsCaptured(array $details = [], ?array $lines = null): Order
{
    $lines ??= [['product' => Product::factory()->create(['name' => 'Winter Jacket', 'price' => 105000]), 'kilograms' => 1]];

    return app(OrderCapture::class)->capture($lines, array_merge([
        'name' => 'Rahim Uddin',
        'phone' => '01712345678',
        'email' => null,
        'address' => 'House 4, Road 12, Banani',
        'district' => 'Dhaka',
        'shop_name' => null,
        'note' => null,
        'payment_method' => PaymentMethod::CashOnDelivery,
    ], $details));
}

/**
 * Ask for a One-Time Code for the number and read it back out of the text
 * it was sent in. Fakes the queue afresh, so the text read is this one.
 */
function codeSentTo(string $phone): string
{
    Queue::fake();

    test()->postJson(route('sign-in.code'), ['phone' => $phone])->assertOk();

    $sent = null;

    Queue::assertPushed(SendText::class, function (SendText $text) use (&$sent): bool {
        preg_match('/\b(\d{4})\b/', $text->message, $match);
        $sent = $match[1] ?? null;

        return true;
    });

    return $sent;
}

/**
 * Sign the test's browser in as the number through a One-Time Code, and keep
 * the cookie that says so for every later request.
 */
function signInAs(string $phone): void
{
    $code = codeSentTo($phone);

    $cookie = test()->postJson(route('sign-in'), ['phone' => $phone, 'code' => $code])
        ->assertOk()
        ->getCookie(CustomerGuard::COOKIE);

    test()->withCredentials()->withCookie(CustomerGuard::COOKIE, $cookie->getValue());
}

/**
 * An Order captured under the gateway, and the Customer choosing it on the
 * Order's Retry Page — which is where a Payment is started. The gateway is
 * made usable first; what it answers is the test's to fake.
 *
 * @param  array<string, mixed>  $details  overrides for the delivery details Capture takes
 */
function payingOnline(GatewayScript $gateway, array $details = []): TestResponse
{
    $gateway->makeUsable();

    $order = orderAsCaptured(['payment_method' => $gateway->method(), ...$details]);

    return test()->post(route('checkout.retry.choose', $order->order_number), [
        'payment_method' => $gateway->method()->value,
    ]);
}

/**
 * Capture an Order under the gateway, with the gateway accepting the Payment,
 * the way a Customer does before being sent off to pay.
 */
function capturedFor(GatewayScript $gateway): Payment
{
    $gateway->fakeStart();

    payingOnline($gateway)->assertRedirect();

    return Payment::sole();
}

/**
 * The Capture Moment for an Order, recorded with what a browser's request
 * said about it — the call the checkout makes once it has captured.
 *
 * @param  array<string, mixed>  $input  what the request carried: click identifiers, in practice
 * @param  array<string, string>  $server
 */
function recordCaptureOf(Order $order, array $input = [], array $server = []): void
{
    app(MomentRecorder::class)->captured($order, TrackingContext::fromRequest(
        Request::create('/checkout', 'POST', $input, server: array_merge([
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36',
        ], $server)),
        'https://shop.test/checkout',
    ));
}

/**
 * The browser reporting a Moment through the beacon, the way a storefront page
 * does: everything in the address, nothing in the body, and the header a
 * browser sets itself.
 *
 * @param  array<string, string>  $query  anything else the address carries
 * @param  array<string, string>  $headers
 */
function reportToBeacon(TrackingMoment $moment = TrackingMoment::Visit, string $identifier = 'load-3f9a2c', array $query = [], array $headers = []): TestResponse
{
    $address = route('tracking.report').'?'.http_build_query(['moment' => $moment->value, 'id' => $identifier, ...$query]);

    return test()->post($address, [], array_merge([
        'Sec-Fetch-Site' => 'same-origin',
        'Referer' => 'https://shop.test/',
    ], $headers));
}

/**
 * The Processing Page's address for a Payment: the one the gateway sends the
 * Customer back to, by our invoice reference and nothing of the gateway's.
 */
function processingUrl(Payment $payment): string
{
    return route('payment.processing', ['method' => $payment->method->value, 'payment' => $payment->invoice_reference]);
}

function verifyUrl(Payment $payment): string
{
    return route('payment.verify', ['method' => $payment->method->value, 'payment' => $payment->invoice_reference]);
}

/**
 * The Notification address for a Payment's method: one per gateway, naming
 * no Payment, because the gateway says which one in its own words.
 */
function notificationUrl(Payment $payment): string
{
    return route('payment.notify', ['method' => $payment->method->value]);
}

/**
 * Forget every HTTP fake laid so far, so one test can hear the same service
 * answer two different ways.
 *
 * `Http::fake()` stacks and the first callback to answer wins — a refusal
 * faked before an acceptance answers for both — and nothing clears the stubs,
 * so the client itself is replaced. Stray requests stay barred.
 *
 * Here rather than in whichever test file needed it first, because the
 * courier suites are two files now and a helper defined in one of them is a
 * fatal error for anybody running only the other.
 */
function answeringAfresh(): void
{
    app()->forgetInstance(HttpFactory::class);

    Http::clearResolvedInstances();
    Http::preventStrayRequests();
}

/**
 * What the User was last told, read the way the panel reads it — out of the
 * session, which the read empties. Asked once in a test, never beside
 * `assertNotified()`, which empties it too.
 *
 * Here rather than in whichever suite needed it first, for the reason
 * `answeringAfresh()` is: a helper defined in one file is a fatal error for
 * anybody running only another.
 */
function lastNotification(): ?Notification
{
    $notifications = new Notifications;
    $notifications->mount();

    return $notifications->notifications->last();
}

/**
 * An Order as Capture leaves it, with the Courier switched on and supplied:
 * one line, a Customer to deliver to, and nothing sent yet.
 *
 * Here rather than in whichever courier suite needed it first, for the reason
 * `answeringAfresh()` is: a helper defined in one courier suite is a fatal
 * error for anybody running only another.
 *
 * @param  array<string, mixed>  $attributes
 */
function orderReadyToSend(CourierScript $script, array $attributes = []): Order
{
    $script->makeUsable();

    $order = Order::factory()->create(array_merge([
        'customer_name' => 'Rahim Uddin',
        'customer_address' => 'House 4, Road 12, Banani',
        'customer_district' => 'Dhaka',
        'delivery_charge' => 6000,
        'payment_method' => PaymentMethod::CashOnDelivery,
    ], $attributes));

    OrderLine::factory()
        ->for($order)
        ->for(Product::factory()->create(['name' => 'Winter Jacket', 'price' => 99000]))
        ->create(['product_name' => 'Winter Jacket', 'unit_price' => 99000, 'quantity' => 1]);

    return $order->refresh();
}

/**
 * Press "Send with courier" on one of the Order's screens, choosing this
 * Courier, the way a User on the call does.
 *
 * The queue reaches the same action by the row rather than by the header, so
 * a User working down a day's Orders sends each parcel without opening any of
 * them. One action, three screens, one dialog.
 *
 * Here rather than in the booking suite that needed it first, for the reason
 * `answeringAfresh()` is: what a booking does to the stock ledger is proved
 * in its own file, and a helper defined in another is a fatal error for
 * anybody running only one of them.
 *
 * @param  array<string, string>  $confirming
 */
function sendWithCourier(Order $order, CourierScript $script, string $page = ViewOrder::class, array $confirming = []): Testable
{
    $answers = array_merge(['courier' => $script->courier()->value], $confirming);

    if ($page === ListOrders::class) {
        return Livewire::test(ListOrders::class)->callTableAction('bookWithCourier', $order, $answers);
    }

    return Livewire::test($page, ['record' => $order->getKey()])
        ->callAction('bookWithCourier', $answers);
}

/**
 * Tick these Orders on the queue and press "Send with courier", choosing this
 * Courier once for the lot — which is the only way this feature is ever
 * reached: the selection is the set, and the selection is what the screen
 * shows.
 *
 * Here rather than in the batch suite that needed it first, for the reason
 * `sendWithCourier()` is: what a batch does to the stock ledger is proved in
 * its own file.
 *
 * @param  list<Order>  $orders
 */
function sendBatchWithCourier(array $orders, CourierScript $script, ?string $courier = null): Testable
{
    return Livewire::test(ListOrders::class)
        ->set('activeTab', 'all')
        ->selectTableRecords(array_map(fn (Order $order): int => $order->getKey(), $orders))
        ->callAction(
            TestAction::make('bookBatchWithCourier')->table()->bulk(),
            data: ['courier' => $courier ?? $script->courier()->value],
        );
}

/**
 * An Order of one Product somebody has counted a shelf of, ready to be sent
 * with this Courier: so many units held, so many of them ordered.
 *
 * Here rather than in the booking suite that needed it first, for the reason
 * `answeringAfresh()` is: what a parcel does to the stock ledger is proved
 * over two files now — the leaving and the coming back — and a helper defined
 * in one of them is a fatal error for anybody running only the other.
 *
 * @return array{Order, Product}
 */
function parcelOfCountedStock(CourierScript $script, int $held = 10, int $ordered = 3): array
{
    $order = orderReadyToSend($script);

    $order->lines()->sole()->update(['quantity' => $ordered]);

    $product = $order->lines()->sole()->product;

    app(StockLedger::class)->recordCount($product, $held, AdjustmentCause::OpeningCount);

    return [$order->refresh(), $product];
}

/**
 * A Product with this many units counted onto its shelf, or none counted at
 * all where no figure is given — which is the unknown a Product nobody has
 * looked at holds, and not a shelf counted empty (ADR-0021).
 *
 * Here rather than in the cover suite that needed it first, for the reason
 * `parcelOfCountedStock()` is: what Days of Cover says is proved over three
 * files — the module, the Dashboard and the Products list — and a helper
 * defined in one of them is a fatal error for anybody running only another.
 */
function shelfOf(string $name, ?int $counted = null): Product
{
    $product = Product::factory()->create(['name' => $name]);

    if ($counted !== null) {
        app(StockLedger::class)->recordCount($product, $counted, AdjustmentCause::OpeningCount);
    }

    return $product;
}

/**
 * A parcel of this Product handed to a Courier on that day.
 *
 * The only thing that ever takes units off a shelf, and therefore the only
 * thing a rate can honestly be read from: an Order nobody could reach was
 * never a parcel (ADR-0021). Time is travelled to rather than a movement
 * being dated by hand, because what the trailing span has to catch is the day
 * the box actually left.
 */
function parcelShippedOn(Product $product, int $units, CarbonImmutable $when): void
{
    test()->travelTo($when);

    $order = Order::factory()->confirmed()->create();

    OrderLine::factory()->for($order)->create([
        'product_id' => $product->getKey(),
        'product_name' => $product->name,
        'unit_price' => $product->price,
        'quantity' => $units,
    ]);

    Shipment::factory()->for($order)->create();

    test()->travelBack();
}

/**
 * What the ledger wrote for the parcels under one reason, and only that: the
 * count that put a Product on the shelf is never one of these.
 *
 * @return Builder<StockMovement>
 */
function parcelMovements(StockMovementReason $reason): Builder
{
    return StockMovement::query()->where('reason', $reason);
}

/**
 * Hand this Courier's parcel over and then have the Courier report one of its
 * own words for it, the way the sweep finds out.
 *
 * Here rather than in the stock suite that needed it first, for the reason
 * `parcelOfCountedStock()` is: what becomes of a parcel is proved over three
 * files now, and a helper defined in one of them is a fatal error for anybody
 * running only another.
 */
function parcelReportedAs(Order $order, CourierScript $script, string $rawStatus): Shipment
{
    $script->fakeBooked();

    sendWithCourier($order, $script);

    answeringAfresh();
    $script->fakeTracking($rawStatus);

    test()->travel($script->reAskIntervalSeconds() + 1)->seconds();
    test()->artisan('couriers:sweep')->assertSuccessful();

    return Shipment::sole()->refresh();
}

/**
 * A parcel a Courier has just taken, with that Courier switched on and
 * supplied — the state the sweep and the webhook both find everything in.
 *
 * Here rather than in whichever courier suite needed it first, for the reason
 * `answeringAfresh()` is: the courier suites are three files now and a helper
 * defined in one of them is a fatal error for anybody running only another.
 *
 * @param  array<string, mixed>  $attributes
 */
function parcelInFlight(CourierScript $script, array $attributes = []): Shipment
{
    $script->makeUsable();

    return Shipment::factory()->create(array_merge([
        'courier' => $script->courier(),
        'status' => ShipmentStatus::Booked,
        'raw_status' => $script->rawStatusesFor(ShipmentStatus::Booked)[0],
        'consignment_reference' => $script->consignmentReference(),
        'tracking_code' => $script->trackingCode(),
        'booked_at' => now(),
    ], $attributes));
}

/**
 * A state this Courier reports while a parcel is still on its way, and its
 * own word for it.
 *
 * In Transit where the Courier has a word for that, On Hold where it has
 * none — Steadfast never says a parcel is moving, and that is expected rather
 * than a gap (ADR-0018). Either way it is somewhere the Courier has not
 * finished with the parcel, which is what these cases need of it.
 *
 * @return array{ShipmentStatus, string}
 */
function stillMoving(CourierScript $script): array
{
    $status = $script->rawStatusesFor(ShipmentStatus::InTransit) === []
        ? ShipmentStatus::OnHold
        : ShipmentStatus::InTransit;

    return [$status, $script->rawStatusesFor($status)[0]];
}

/**
 * The Announcement address for a Courier: one per Courier, naming no parcel,
 * because the Courier says which one in its own words.
 */
function announcementUrl(CourierScript $script): string
{
    return route('courier.notify', ['courier' => $script->courier()->value]);
}

/**
 * The instant every reporting test cuts its windows from: a Wednesday
 * morning in Dhaka. Last 7 days therefore runs from Thursday the 3rd to
 * Wednesday the 9th, and the comparison window from the 27th to the 2nd.
 */
function reportingNow(): CarbonImmutable
{
    return CarbonImmutable::parse('2026-09-09 10:00:00', 'Asia/Dhaka');
}

/**
 * An Order captured at an instant, its subtotal given and its total derived
 * with the Delivery Charge on top, the way the observer keeps it. Status is
 * a factory state applied at creation rather than a later update, because
 * a save re-reads the subtotal from the lines an Order made this way does
 * not have.
 *
 * @param  array<string, mixed>  $attributes
 */
function orderCapturedAt(string $instant, int $subtotal = 50000, array $attributes = [], OrderStatus $status = OrderStatus::New): Order
{
    $factory = match ($status) {
        OrderStatus::New => Order::factory(),
        OrderStatus::Confirmed => Order::factory()->confirmed(),
        OrderStatus::Cancelled => Order::factory()->cancelled(),
    };

    return $factory->create(array_merge([
        'subtotal' => $subtotal,
        'delivery_charge' => 6000,
        'created_at' => CarbonImmutable::parse($instant, 'Asia/Dhaka')->utc(),
    ], $attributes));
}

/**
 * Lines added to an Order, one per weight given, each a Product of its own:
 * what Kilograms Sold reads, since an Order's Kilograms are the sum of its
 * lines. The line observer re-reads the subtotal, so a test weighing an
 * Order asserts on Kilograms rather than on the money it was captured with.
 */
function weighing(Order $order, int ...$kilograms): Order
{
    foreach ($kilograms as $weight) {
        OrderLine::factory()->for($order)->create(['quantity' => $weight]);
    }

    return $order;
}

/**
 * `bongotea:install`'s answers given as options, as an unattended run gives
 * them: the first Owner, and the store opened on the demo unless `$extra`
 * says otherwise.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function installOptions(array $extra = []): array
{
    return [
        '--owner-name' => 'Rumi',
        '--owner-email' => 'rumi@example.com',
        '--owner-password' => 'secret-password',
        '--demo' => true,
        ...$extra,
    ];
}
