<?php

namespace App\Support\Tracking;

use App\Enums\TrackingMoment;
use App\Models\Destination;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Page;
use App\Models\Product;
use App\Models\TrackingEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What a Page will send, shown before it sends anything.
 *
 * Built by making a sample Order and a sample outbox row and asking the
 * Provider's own driver what it would put on the wire for it — the very
 * method the sweep calls. Nothing here describes a payload: a description
 * would be a second implementation of the wire format, and it would be wrong
 * within a release of the first one it disagreed with.
 *
 * Nothing is saved. The Order, its lines and the rows are made in memory with
 * their relations set by hand, so a Merchant opening the Tracking tab writes
 * no Order, no outbox row and no HTTP request.
 *
 * The match keys come out hashed, because that is what leaves the server — a
 * Merchant asking "what do you send about my Customer" is owed the answer as
 * Meta receives it, not the plain phone number they already have.
 */
class TrackingPreview
{
    /** The sample Customer every preview is drawn for, in the shape a real Capture writes. */
    private const CUSTOMER_NAME = 'Rahim Uddin';

    private const CUSTOMER_PHONE = '+8801712345678';

    private const CUSTOMER_EMAIL = 'rahim@example.com';

    private const CUSTOMER_DISTRICT = 'Dhaka';

    /** A Customer is their phone number, so a real external id is derived from one; this stands in for that. */
    private const CUSTOMER_ID = 1;

    private const ORDER_NUMBER = 'SAMPLE23';

    /** What the sample Order is worth, in minor units, before the Delivery Charge the total carries. */
    private const SUBTOTAL = 99000;

    private const DELIVERY_CHARGE = 6000;

    /** What a Page that sells nothing yet previews with, so a draft still shows its shape. */
    private const SAMPLE_PRODUCT = 'Sample Product';

    public function __construct(private readonly TrackingPlans $plans) {}

    /**
     * Every event this Page will report, in the order the Moments produce
     * them, each with the payload its Destination's Provider would send.
     *
     * An event is previewed once however many Moments produce it, and named
     * with all of them: a Purchase is reported when a Cash on Delivery Order
     * is placed *or* when an online one is verified Paid, never both, and a
     * preview claiming one of those would be wrong on half the Pages.
     *
     * @return list<array{destination: Destination, moments: list<TrackingMoment>, event: string, payload: array<string, mixed>}>
     */
    public function forConfig(TrackingConfig $config, ?Page $page): array
    {
        $plan = $this->plans->forConfig($config);
        $order = $this->sampleOrder($page);

        $previews = [];

        foreach ($plan->destinations() as $destination) {
            foreach ($this->momentsByEvent($plan, $destination) as $event => $moments) {
                $previews[] = [
                    'destination' => $destination,
                    'moments' => $moments,
                    'event' => $event,
                    'payload' => $destination->driver()->payloadFor(
                        $this->sampleRow($destination, $moments[0], (string) $event, $page, $order, $plan->sendsContentDetail()),
                    ),
                ];
            }
        }

        return $previews;
    }

    /**
     * Which Moments produce each of this Destination's events, in the order
     * the Moments happen.
     *
     * @return array<string, list<TrackingMoment>>
     */
    private function momentsByEvent(TrackingPlan $plan, Destination $destination): array
    {
        $events = [];

        foreach (TrackingMoment::cases() as $moment) {
            foreach ($plan->eventsFor($destination, $moment) as $event) {
                $events[$event][] = $moment;
            }
        }

        return $events;
    }

    /**
     * One sample outbox row, exactly as the first Moment that produces this
     * event would write it.
     *
     * A Moment about a page load carries no Order — its server copy reports
     * no money and no basket — so the row is built the way the recorder
     * builds it rather than given the sample Order regardless.
     */
    private function sampleRow(
        Destination $destination,
        TrackingMoment $moment,
        string $event,
        ?Page $page,
        Order $order,
        bool $contentDetail,
    ): TrackingEvent {
        $aboutTheOrder = $moment === TrackingMoment::Capture || $moment === TrackingMoment::Paid;

        $row = new TrackingEvent([
            'destination_id' => $destination->getKey(),
            'moment' => $moment,
            'event' => $event,
            'deduplication_id' => $aboutTheOrder ? self::ORDER_NUMBER : 'a1b2c3d4-sample-load',
            'occurred_at' => Carbon::now(),
            'page_slug' => $page?->slug,
            'source_url' => $page === null ? url('/') : route('landing.show', $page->slug),
            'ip_address' => '203.0.113.42',
            'user_agent' => 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36',
            'click_ids' => $this->clickIds($destination),
            'content_detail' => $contentDetail,
        ]);

        /** Set rather than saved: a driver reads `$event->order`, and a preview writes nothing. */
        $row->setRelation('order', $aboutTheOrder ? $order : null);

        return $row;
    }

    /**
     * A sample click identifier per name this Provider recognises, so the
     * preview shows the keys that carry attribution rather than leaving them
     * out as a Page nobody has clicked an ad onto would.
     *
     * @return array<string, string>
     */
    private function clickIds(Destination $destination): array
    {
        $clickIds = [];

        foreach ($destination->driver()->clickIdNames() as $name) {
            $clickIds[$name] = 'sample.'.$name;
        }

        return $clickIds;
    }

    /**
     * An Order as this Page would capture one: its own Products where it
     * sells any, one stand-in where it sells none yet, and the Delivery
     * Charge in the total the way every reported value carries it.
     */
    private function sampleOrder(?Page $page): Order
    {
        $order = new Order([
            'customer_id' => self::CUSTOMER_ID,
            'page_id' => $page?->getKey(),
            'page_slug' => $page?->slug,
            'customer_name' => self::CUSTOMER_NAME,
            'customer_phone' => self::CUSTOMER_PHONE,
            'customer_email' => self::CUSTOMER_EMAIL,
            'customer_district' => self::CUSTOMER_DISTRICT,
            'delivery_charge' => self::DELIVERY_CHARGE,
        ]);

        $lines = $this->sampleLines($page);

        $order->order_number = self::ORDER_NUMBER;
        $order->subtotal = (int) $lines->sum(fn (OrderLine $line): int => $line->unit_price * $line->quantity);
        $order->total = $order->subtotal + self::DELIVERY_CHARGE;

        $order->setRelation('lines', $lines);

        return $order;
    }

    /**
     * @return Collection<int, OrderLine>
     */
    private function sampleLines(?Page $page): Collection
    {
        $products = $page?->products ?? new Collection;

        if ($products->isEmpty()) {
            return new Collection([new OrderLine([
                'product_name' => self::SAMPLE_PRODUCT,
                'quantity' => 1,
                'unit_price' => self::SUBTOTAL,
            ])]);
        }

        return $products->map(fn (Product $product): OrderLine => new OrderLine([
            'product_id' => $product->getKey(),
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => $product->price,
        ]))->values();
    }
}
