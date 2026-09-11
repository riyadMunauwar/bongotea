<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Page;
use App\Models\Payment;
use App\Models\Product;
use App\Shortcodes\AttributeSchema;
use App\Shortcodes\Node;
use App\Shortcodes\ShortcodeDefinition;
use App\Shortcodes\ShortcodeRegistry;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Checkout\PricingFingerprint;
use App\Support\PageCache\StaticPageCache;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Pest\Expectation;
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
| Page cache isolation
|--------------------------------------------------------------------------
|
| The static cache is pointed at a scratch directory for every feature test.
| public/page-cache is served by the webserver publish-blind, so a file left
| there by the suite would be real content on a real site.
|
*/

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

pest()->beforeEach(function (): void {
    $this->cacheDirectory = storage_path('framework/testing/page-cache-'.bin2hex(random_bytes(4)));

    $this->app->instance(StaticPageCache::class, new StaticPageCache(
        $this->app->make(Filesystem::class),
        $this->cacheDirectory,
    ));

    $this->cache = $this->app->make(StaticPageCache::class);
})->in('Feature');

pest()->afterEach(function (): void {
    if (isset($this->cacheDirectory)) {
        (new Filesystem)->deleteDirectory($this->cacheDirectory);
    }
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
 * A published Page selling whatever Products it is handed, the way a Customer
 * meets checkout. One is the shape every Page had before Products could be
 * linked in numbers; more is what a combo or an upsell page looks like.
 */
function sellingPage(Product|iterable $products, string $slug = 'promo'): Page
{
    $page = Page::factory()->published()->withBody('<main>[checkout]</main>')->create(['slug' => $slug]);

    $page->products()->attach($products instanceof Product
        ? $products
        : collect($products)->map(fn (Product $product): int => $product->getKey())->all());

    return $page;
}

/**
 * A submission of one of everything the Page sells.
 *
 * Quantities are keyed by Product id, the way the rendered form names them, so
 * a test never has to know what order the admin arranged them in.
 *
 * The fingerprint is taken as the payload is built, which is where a rendered
 * form takes it: a test that changes a price or a Product afterwards is a test
 * about a visitor holding bytes that went stale under them.
 *
 * @return array<string, mixed>
 */
function capturePayload(Page $page, array $overrides = []): array
{
    return array_merge([
        'page' => $page->slug,
        'fingerprint' => PricingFingerprint::for($page),
        'name' => 'Rahim Uddin',
        'phone' => '01712345678',
        'address' => 'House 4, Road 12, Banani',
        'district' => 'Dhaka',
        'quantities' => $page->products->mapWithKeys(
            fn (Product $product): array => [$product->getKey() => 1],
        )->all(),
    ], $overrides);
}

/**
 * The same Page, with its payment section written as given.
 *
 * @param  list<mixed>  $methods  What the stored list holds, as stored — names, or anything else a test wants read totally.
 */
function payingBy(Page $page, array $methods, bool $showLogos = true, bool $hideLoneCash = true): Page
{
    $page->update(['checkout_config' => CheckoutConfig::fromArray([
        ...$page->checkout_config->toArray(),
        'payment_methods' => $methods,
        'show_payment_logos' => $showLogos,
        'hide_lone_cash_choice' => $hideLoneCash,
    ])]);

    return $page->refresh();
}

/**
 * A published Page offering the gateway first and cash on delivery second,
 * with the installation set up so the gateway is usable.
 */
function pageOffering(GatewayScript $gateway): Page
{
    $gateway->makeUsable();

    return payingBy(sellingPage(Product::factory()->create(['price' => 99000])), [$gateway->method()->value, 'cash_on_delivery']);
}

/**
 * Capture an Order under the gateway, with the gateway accepting the Payment,
 * the way a Customer does before being sent off to pay.
 *
 * @param  array<string, mixed>  $overrides  anything else the submission carries — what the island fills in from the browser, in practice.
 */
function capturedFor(GatewayScript $gateway, array $overrides = []): Payment
{
    $gateway->fakeStart();

    test()->postJson(route('checkout.store'), capturePayload(pageOffering($gateway), [
        'payment_method' => $gateway->method()->value,
        ...$overrides,
    ]))->assertCreated();

    return Payment::sole();
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
 * Register a throwaway Shortcode Definition for the duration of one test.
 *
 * The registry is a singleton rebuilt per test, so this is how a test
 * exercises the shortcode *mechanism* without coupling itself to the one
 * shortcode v1 happens to ship.
 *
 * @param  Closure(Node, Page): string  $render
 */
function registerShortcode(string $name, Closure $render): void
{
    app(ShortcodeRegistry::class)->register(new class($name, $render) implements ShortcodeDefinition
    {
        public function __construct(
            private readonly string $name,
            private readonly Closure $render,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function attributes(): AttributeSchema
        {
            return AttributeSchema::none();
        }

        public function render(Node $node, Page $page): string
        {
            return ($this->render)($node, $page);
        }
    });
}

/**
 * The one <style> block a served page carries, without the markup around it:
 * a Checkout's on a landing page, or the Order Complete Page's own.
 *
 * Read out of the served page rather than off a class, because what is being
 * pinned is what a visitor's browser receives.
 */
function styleBlock(string $html): string
{
    preg_match('/<style>(.*?)<\/style>/s', $html, $block);

    return $block[1] ?? '';
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
