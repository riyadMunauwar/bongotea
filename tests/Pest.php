<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Pages\Pages\EditPage;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Page;
use App\Models\Payment;
use App\Models\Product;
use App\Shortcodes\AttributeSchema;
use App\Shortcodes\Definitions\CheckoutShortcode;
use App\Shortcodes\Node;
use App\Shortcodes\ShortcodeDefinition;
use App\Shortcodes\ShortcodeRegistry;
use App\Support\Checkout\CheckoutConfig;
use App\Support\Checkout\PricingFingerprint;
use App\Support\PageCache\StaticPageCache;
use App\Support\Reporting\ReportingWindow;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
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

/**
 * A Page worth reporting on and another whose Orders must never reach it.
 *
 * The first took one Order today and one three days ago; the second took one
 * today. Each Order's money comes from its Lines: the Order Line observer
 * recalculates the subtotal on every line saved, and the Order observer puts
 * the Delivery Charge on top. So the reported Page took 660.00 today (two
 * Sylhet tea at 300.00, paid through bKash) and 160.00 three days ago (one
 * Clay mug, due on delivery) — 820.00 across the window, 410.00 an Order —
 * while the other Page took 960.00 today.
 *
 * Every Order is delivered under a label and to a District its own Page uses
 * and the other never does, so a figure about delivery names the Page it
 * belongs to as plainly as a figure about money does.
 *
 * @return array{Page, Page}
 */
function twoReportedPages(): array
{
    $reported = Page::factory()->published()->create(['title' => 'Summer combo']);
    $other = Page::factory()->published()->create(['title' => 'Winter combo']);

    $tea = Product::factory()->create(['name' => 'Sylhet tea', 'price' => 30000]);
    $mug = Product::factory()->create(['name' => 'Clay mug', 'price' => 10000]);
    $rug = Product::factory()->create(['name' => 'Rangpur rug', 'price' => 90000]);

    $today = Order::factory()->create([
        'page_id' => $reported->id,
        'delivery_label' => 'Inside Dhaka',
        'delivery_charge' => 6000,
        'customer_district' => 'Dhaka',
        'payment_method' => PaymentMethod::Bkash,
        'created_at' => now(),
    ]);
    Payment::factory()->for($today)->paid()->create(['method' => PaymentMethod::Bkash]);
    OrderLine::factory()->for($today)->create(['product_id' => $tea->id, 'product_name' => $tea->name, 'unit_price' => $tea->price, 'quantity' => 2]);

    $earlier = Order::factory()->create([
        'page_id' => $reported->id,
        'delivery_label' => 'Inside Dhaka',
        'delivery_charge' => 6000,
        'customer_district' => 'Dhaka',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'created_at' => now()->subDays(3),
    ]);
    OrderLine::factory()->for($earlier)->create(['product_id' => $mug->id, 'product_name' => $mug->name, 'unit_price' => $mug->price, 'quantity' => 1]);

    $elsewhere = Order::factory()->create([
        'page_id' => $other->id,
        'delivery_label' => 'Outside Dhaka',
        'delivery_charge' => 6000,
        'customer_district' => 'Sylhet',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'created_at' => now(),
    ]);
    OrderLine::factory()->for($elsewhere)->create(['product_id' => $rug->id, 'product_name' => $rug->name, 'unit_price' => $rug->price, 'quantity' => 1]);

    return [$reported, $other];
}

/**
 * The data a chart widget hands to Chart.js, read off the widget rather
 * than out of the HTML, for one Page and one preset.
 *
 * @return array{labels: list<string>, datasets: list<array<string, mixed>>}
 */
function pageChartData(string $widget, Page $page, string $preset = 'last_7_days'): array
{
    return (fn (): array => $this->getData())->call(
        Livewire::test($widget, ['record' => $page, 'pageFilters' => [ReportingWindow::PRESET_FILTER => $preset]])->instance(),
    );
}

/**
 * The beacon a served document carries: the address it posts to, and the name
 * of the identifier it appends.
 *
 * Read out of the served bytes rather than built, because what is being
 * pinned is that the Pixel and the beacon were handed the same one.
 *
 * @return array{address: string, identifier: string}
 */
function beaconIn(string $html): array
{
    $found = [];

    expect(preg_match('/var gioBeacon = "([^"]+)" \+ encodeURIComponent\(([A-Za-z_$][A-Za-z0-9_$]*)\);/', $html, $found))
        ->toBe(1, 'The document carries no beacon.');

    return ['address' => $found[1], 'identifier' => $found[2]];
}

/**
 * The part of a served document that runs as the page loads: everything
 * before the listener waiting for a visitor to start filling the Checkout.
 *
 * What a Page reports on load and what it reports later are two questions,
 * and one document holds both — so a test asking about the Visit reads this
 * rather than the whole of the bytes.
 */
function onLoad(string $html): string
{
    return Str::before($html, 'document.addEventListener("'.CheckoutShortcode::STARTED_EVENT.'"');
}

/**
 * The address the browser reports a Checkout Started to, and the names of the
 * two things it appends: the identifier this page load is deduplicated on,
 * and the Selection the items ride from.
 *
 * @return array{address: string, identifier: string, selection: string}
 */
function startedBeaconIn(string $html): array
{
    $found = [];

    expect(preg_match('/gioReport\("([^"]+)" \+ encodeURIComponent\(([A-Za-z_$][A-Za-z0-9_$]*)\) \+ \'&items=\' \+ encodeURIComponent\(\s*\(?([A-Za-z_$][A-Za-z0-9_$]*)\.lines/', $html, $found))
        ->toBe(1, 'The document reports no Checkout Started to the server.');

    return ['address' => $found[1], 'identifier' => $found[2], 'selection' => $found[3]];
}

/*
|--------------------------------------------------------------------------
| Reading an admin form
|--------------------------------------------------------------------------
|
| The Page form is read by more than one suite — the authoring tests and the
| tracking ones — so the walk over a built schema lives here rather than in
| whichever file needed it first. Everything below asks the form a Merchant
| is actually served, never the schema classes that build it.
|
*/
/**
 * The tabs directly inside a schema or a component, in the order a merchant
 * meets them.
 *
 * A `Tabs` is transparent here: it is the arrangement, not a thing on screen,
 * so asking a Tab what tabs are inside it answers with the nested ones rather
 * than with the container that holds them.
 *
 * @return list<Tab>
 */
function tabsInside(Schema|Component $node): array
{
    $schema = $node instanceof Schema ? $node : $node->getChildSchema();

    $tabs = [];

    foreach ($schema?->getComponents(withHidden: true) ?? [] as $component) {
        if ($component instanceof Tabs) {
            $tabs = [...$tabs, ...tabsInside($component)];

            continue;
        }

        if ($component instanceof Tab) {
            $tabs[] = $component;
        }
    }

    return $tabs;
}

/**
 * Every state path a merchant can type into somewhere under this component,
 * however deeply it is nested.
 *
 * Read without the form's own root, so a path here is the one the Config is
 * written at rather than the Livewire property the panel happens to hold the
 * whole form in.
 *
 * @return list<string>
 */
function statePathsInside(Schema|Component $node): array
{
    $schemas = $node instanceof Schema ? [$node] : $node->getChildSchemas(withHidden: true);

    $paths = [];

    foreach ($schemas as $schema) {
        foreach ($schema->getComponents(withHidden: true) as $component) {
            if ($component instanceof Field) {
                $paths[] = Str::after(
                    $component->getStatePath(),
                    $component->getRootContainer()->getStatePath().'.',
                );
            }

            $paths = [...$paths, ...statePathsInside($component)];
        }
    }

    return $paths;
}

/**
 * One control a merchant types into, found by the path its value is written
 * at.
 *
 * The same walk `statePathsInside()` makes, stopping at the field rather than
 * collecting every path — so a test can ask what a control is *offering* and
 * not only that it is on the right tab.
 */
function fieldInside(Schema|Component $node, string $path): ?Field
{
    $schemas = $node instanceof Schema ? [$node] : $node->getChildSchemas(withHidden: true);

    foreach ($schemas as $schema) {
        foreach ($schema->getComponents(withHidden: true) as $component) {
            if ($component instanceof Field && Str::after(
                $component->getStatePath(),
                $component->getRootContainer()->getStatePath().'.',
            ) === $path) {
                return $component;
            }

            if ($found = fieldInside($component, $path)) {
                return $found;
            }
        }
    }

    return null;
}

/**
 * The form a merchant is actually served on the edit screen, rather than the
 * schema classes that build it — so what is asserted is what they open.
 */
function editFormOf(Page $page): Schema
{
    return Livewire::test(EditPage::class, ['record' => $page->getKey()])
        ->instance()
        ->getSchema('form');
}
